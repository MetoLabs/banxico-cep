<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MetoLabs\Banxico\Cep\CepClient;
use MetoLabs\Banxico\Cep\CepFormat;
use MetoLabs\Banxico\Cep\ErrorCode;
use MetoLabs\Banxico\Cep\ErrorMessages;
use MetoLabs\Banxico\Cep\Exception\PaymentNotFoundException;
use MetoLabs\Banxico\Cep\PaymentQuery;
use PHPUnit\Framework\TestCase;

final class CepClientTest extends TestCase
{
    public function test_it_downloads_a_pdf(): void
    {
        $client = $this->client([
            new Response(200, [], '<html>session</html>'),
            new Response(200, [], '<div>Payment data</div>'),
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.7 test'),
        ]);

        self::assertSame('%PDF-1.7 test', $client->download($this->query(), CepFormat::PDF));
    }

    public function test_it_throws_payment_not_found_with_a_stable_error_code(): void
    {
        $client = $this->client([
            new Response(200, [], '<html>session</html>'),
            new Response(200, [], 'No se encontró ningún pago con la información proporcionada'),
        ]);

        try {
            $client->download($this->query());
            self::fail('Expected PaymentNotFoundException.');
        } catch (PaymentNotFoundException $e) {
            self::assertSame(ErrorCode::PaymentNotFound, $e->errorCode);
            self::assertSame('The payment could not be found with the provided information.', $e->getMessage());
        }
    }

    public function test_error_messages_are_configurable(): void
    {
        $client = $this->client(
            [
                new Response(200, [], '<html>session</html>'),
                new Response(200, [], 'No se encontró ningún pago con la información proporcionada'),
            ],
            new ErrorMessages([
                ErrorCode::PaymentNotFound->value => 'Custom payment not found message.',
            ]),
        );

        $this->expectExceptionMessage('Custom payment not found message.');
        $client->download($this->query());
    }

    public function test_consult_returns_details_without_requesting_a_document(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Set-Cookie' => 'session=test; Path=/']),
            new Response(200, [], file_get_contents(__DIR__ . '/fixtures/payment-status.html')),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new CepClient(http: new Client(['handler' => $stack, 'base_uri' => CepClient::DEFAULT_BASE_URL]));
        $payments = $client->consult($this->query());
        self::assertCount(1, $payments);
        self::assertSame('Liquidado', $payments[0]->status);
        self::assertSame('TEST-TRACKING', $payments[0]->trackingKey);
        self::assertSame('1234567', $payments[0]->referenceNumber);
        self::assertSame('TEST SENDER', $payments[0]->senderBank);
        self::assertSame('TEST RECEIVER', $payments[0]->receiverBank);
        self::assertSame('012000000000000000', $payments[0]->beneficiaryAccount);
        self::assertSame('1500.00', $payments[0]->amount);
        self::assertSame('01/01/2000 10:05:02', $payments[0]->processedAt);
        self::assertSame($payments[0]->toArray(), json_decode(json_encode($payments[0]), true));
        self::assertCount(2, $history);
        self::assertSame('/cep/valida.do', $history[1]['request']->getUri()->getPath());
        self::assertSame('session=test', $history[1]['request']->getHeaderLine('Cookie'));
        parse_str((string) $history[1]['request']->getBody(), $payload);
        self::assertSame('0', $payload['tipoConsulta']);
    }

    public function test_consult_preserves_multiple_matches_and_missing_optional_fields(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/payment-status.html');
        $html = preg_replace('/<tr><td>Monto<\/td>.*?<\/tr>/', '', $html);
        $client = $this->client([new Response(200), new Response(200, [], $html . $html)]);
        $payments = $client->consult($this->query());
        self::assertCount(2, $payments);
        self::assertNull($payments[0]->amount);
    }

    public function test_consult_rejects_unknown_or_error_html(): void
    {
        foreach (['', '<html>CAPTCHA required</html>', '<div>meta:stats=ERR</div>'] as $html) {
            try {
                $this->client([new Response(200), new Response(200, [], $html)])->consult($this->query());
                self::fail('Expected an exception.');
            } catch (\MetoLabs\Banxico\Cep\Exception\CepException $e) {
                self::assertSame($html === '' ? ErrorCode::EmptyResponse : ErrorCode::InvalidResponse, $e->errorCode);
            }
        }
    }

    public function test_consult_reports_not_found_and_rate_limits(): void
    {
        foreach ([
            'No se encontró ningún pago con la información proporcionada' => ErrorCode::PaymentNotFound,
            'ha excedido el n&uacute;mero m&aacute;ximo de consultas' => ErrorCode::RateLimitExceeded,
        ] as $html => $code) {
            try {
                $this->client([new Response(200), new Response(200, [], $html)])->consult($this->query());
                self::fail('Expected an exception.');
            } catch (\MetoLabs\Banxico\Cep\Exception\CepException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_consult_wraps_transport_errors(): void
    {
        $client = $this->client([new Response(200), new Response(503)]);
        try {
            $client->consult($this->query());
            self::fail('Expected an exception.');
        } catch (\MetoLabs\Banxico\Cep\Exception\CepException $e) {
            self::assertSame(ErrorCode::HttpRequestFailed, $e->errorCode);
        }
    }

    public function test_receiver_bank_is_resolved_using_the_catalogue_not_a_fixed_prefix(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [],
            '{"instituciones":[["40012","TEST BANK"],["90732","TEST SENDER"],["37166","TEST INSTITUTION"]]}') ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new CepClient(http: new Client(['handler' => $stack, 'base_uri' => CepClient::DEFAULT_BASE_URL]));
        self::assertSame('37166', $client->receiverBankFromClabe('166000000000000000', new DateTimeImmutable('2000-01-01')));
        self::assertSame('fecha=01-01-2000', $history[0]['request']->getUri()->getQuery());
    }

    public function test_invalid_clabe_is_rejected_before_making_requests(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->receiverBankFromClabe('123', new DateTimeImmutable());
    }

    public function test_unknown_clabe_institution_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([new Response(200, [], '{"instituciones":[]}')])
            ->receiverBankFromClabe('999000000000000000', new DateTimeImmutable());
    }

    /** @param array<Response> $responses */
    private function client(array $responses, ?ErrorMessages $messages = null): CepClient
    {
        $mock = new MockHandler($responses);
        $http = new Client([
            'handler' => HandlerStack::create($mock),
            'base_uri' => CepClient::DEFAULT_BASE_URL,
        ]);

        return new CepClient(http: $http, messages: $messages);
    }

    private function query(): PaymentQuery
    {
        return PaymentQuery::byTrackingKey(
            paymentDate: new DateTimeImmutable('2000-01-02'),
            trackingKey: 'TRACKING-123',
            senderBank: '40012',
            receiverBank: '90646',
            beneficiaryAccount: '646000000000000000',
            amount: '1500.00',
        );
    }
}
