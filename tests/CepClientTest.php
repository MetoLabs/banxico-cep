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
            paymentDate: new DateTimeImmutable('2026-09-16'),
            trackingKey: 'TRACKING-123',
            senderBank: '40012',
            receiverBank: '90646',
            beneficiaryAccount: '646180157034181234',
            amount: '1500.00',
        );
    }
}
