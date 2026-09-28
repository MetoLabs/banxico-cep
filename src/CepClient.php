<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use MetoLabs\Banxico\Cep\Exception\CepException;
use MetoLabs\Banxico\Cep\Exception\CepNotAvailableException;
use MetoLabs\Banxico\Cep\Exception\PaymentNotFoundException;
use MetoLabs\Banxico\Cep\Exception\RateLimitExceededException;

final class CepClient
{
    public const DEFAULT_BASE_URL = 'https://www.banxico.org.mx/cep/';

    private const PAYMENT_NOT_FOUND_MARKERS = [
        'No se encontró ningún pago con la información proporcionada',
        'El SPEI no ha recibido una orden de pago que cumpla con el criterio de búsqueda especificado',
    ];

    private const CEP_NOT_AVAILABLE_MARKER =
        'Con la información proporcionada se identificó el siguiente pago';

    private const RATE_LIMIT_MARKERS = [
        'ha excedido el número máximo de consultas',
        'ha excedido el n&uacute;mero m&aacute;ximo de consultas',
    ];

    private ClientInterface $http;
    private ErrorMessages $messages;

    public function __construct(
        ?ClientInterface $http = null,
        ?ErrorMessages $messages = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
    ) {
        $this->messages = $messages ?? new ErrorMessages();
        $this->http = $http ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connect_timeout' => 10.0,
            'verify' => true,
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; MetoLabs Banxico CEP PHP Client/1.0)',
                'Accept' => '*/*',
            ],
        ]);
    }

    /**
     * Query SPEI payment details without downloading a CEP document.
     * The result is payment status information, not proof of accreditation.
     *
     * @return list<PaymentDetails> A reference can match more than one payment.
     * @throws CepException
     */
    public function consult(PaymentQuery $query): array
    {
        $cookies = new CookieJar();
        $this->initializeSession($cookies);
        $html = $this->validatePayment($query, $cookies, consultation: true);

        try {
            return PaymentDetails::fromHtml($html);
        } catch (\UnexpectedValueException $e) {
            throw $this->exception(ErrorCode::InvalidResponse, $e);
        }
    }

    /** Resolve the full SPEI institution code using the catalogue for the payment date. */
    public function receiverBankFromClabe(string $clabe, \DateTimeInterface $paymentDate): string
    {
        if (!preg_match('/^[0-9]{18}$/D', $clabe)) {
            throw new \InvalidArgumentException('CLABE must contain exactly 18 digits.');
        }

        try {
            $response = $this->http->request('GET', 'instituciones.do', [
                'timeout' => $this->timeout,
                'query' => ['fecha' => $paymentDate->format('d-m-Y')],
            ]);
        } catch (GuzzleException $e) {
            throw $this->exception(ErrorCode::HttpRequestFailed, $e);
        }

        $body = (string) $response->getBody();
        $this->assertNoRateLimit($this->decode($body));
        $catalogue = json_decode($body, true);
        if (!is_array($catalogue) || !isset($catalogue['instituciones']) || !is_array($catalogue['instituciones'])) {
            throw $this->exception(ErrorCode::InvalidResponse);
        }

        $matches = [];
        foreach ($catalogue['instituciones'] as $institution) {
            if (!is_array($institution) || !isset($institution[0]) || !is_scalar($institution[0])) {
                throw $this->exception(ErrorCode::InvalidResponse);
            }
            $code = (string) $institution[0];
            if (substr($code, -3) === substr($clabe, 0, 3)) {
                $matches[] = $code;
            }
        }
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('CLABE does not resolve to a unique institution for the payment date.');
        }

        return $matches[0];
    }

    /**
     * Download the CEP and return its raw bytes.
     *
     * @throws CepException
     */
    public function download(
        PaymentQuery $query,
        CepFormat $format = CepFormat::PDF,
    ): string {
        $cookies = new CookieJar();

        $this->initializeSession($cookies);
        $this->validatePayment($query, $cookies);

        return $this->downloadDocument($format, $cookies);
    }

    /**
     * Download the CEP directly to a local path.
     *
     * @throws CepException
     */
    public function downloadTo(
        PaymentQuery $query,
        string $path,
        CepFormat $format = CepFormat::PDF,
    ): void {
        $bytes = $this->download($query, $format);

        if (@file_put_contents($path, $bytes) === false) {
            throw $this->exception(ErrorCode::FileWriteFailed);
        }
    }

    /**
     * Check whether the CEP is currently available.
     * HTTP/transport errors are intentionally not converted to false.
     *
     * @throws CepException
     */
    public function exists(PaymentQuery $query): bool
    {
        try {
            $cookies = new CookieJar();
            $this->initializeSession($cookies);
            $this->validatePayment($query, $cookies);

            return true;
        } catch (PaymentNotFoundException|CepNotAvailableException) {
            return false;
        }
    }

    private function initializeSession(CookieJar $cookies): void
    {
        try {
            $this->http->request('GET', '', [
                'cookies' => $cookies,
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException $e) {
            throw $this->exception(ErrorCode::HttpRequestFailed, $e);
        }
    }

    private function validatePayment(PaymentQuery $query, CookieJar $cookies, bool $consultation = false): string
    {
        try {
            $response = $this->http->request('POST', 'valida.do', [
                'cookies' => $cookies,
                'timeout' => $this->timeout,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Origin' => $this->origin(),
                    'Referer' => $this->baseUrl,
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
                'form_params' => array_replace($query->toBanxicoPayload(), [
                    'tipoConsulta' => $consultation ? 0 : 1,
                ]),
            ]);
        } catch (GuzzleException $e) {
            throw $this->exception(ErrorCode::HttpRequestFailed, $e);
        }

        $html = (string) $response->getBody();
        $body = $this->decode($html);

        if ($body === '') {
            throw $this->exception(ErrorCode::EmptyResponse);
        }

        $this->assertNoRateLimit($body);

        foreach (self::PAYMENT_NOT_FOUND_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                throw new PaymentNotFoundException(
                    ErrorCode::PaymentNotFound,
                    $this->messages->get(ErrorCode::PaymentNotFound),
                );
            }
        }

        if (!$consultation && str_contains($body, self::CEP_NOT_AVAILABLE_MARKER)) {
            throw new CepNotAvailableException(
                ErrorCode::CepNotAvailable,
                $this->messages->get(ErrorCode::CepNotAvailable),
            );
        }

        return $html;
    }

    private function downloadDocument(CepFormat $format, CookieJar $cookies): string
    {
        try {
            $response = $this->http->request('GET', 'descarga.do', [
                'cookies' => $cookies,
                'timeout' => $this->timeout,
                'query' => ['formato' => $format->value],
                'headers' => ['Referer' => $this->baseUrl],
            ]);
        } catch (GuzzleException $e) {
            throw $this->exception(ErrorCode::HttpRequestFailed, $e);
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            throw $this->exception(ErrorCode::EmptyResponse);
        }

        $decoded = $this->decode($body);
        $this->assertNoRateLimit($decoded);

        if ($this->looksLikeBanxicoErrorPage($decoded)) {
            throw $this->exception(ErrorCode::InvalidResponse);
        }

        return $body;
    }

    private function assertNoRateLimit(string $body): void
    {
        foreach (self::RATE_LIMIT_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                throw new RateLimitExceededException(
                    ErrorCode::RateLimitExceeded,
                    $this->messages->get(ErrorCode::RateLimitExceeded),
                );
            }
        }
    }

    private function decode(string $body): string
    {
        return html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function looksLikeBanxicoErrorPage(string $body): bool
    {
        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '<!DOCTYPE html')
            || str_starts_with($trimmed, '<html')
            || str_contains($trimmed, '<div class="cuerpo-msg"');
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'https://www.banxico.org.mx';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function exception(ErrorCode $code, ?\Throwable $previous = null): CepException
    {
        return new CepException(
            errorCode: $code,
            message: $this->messages->get($code),
            previous: $previous,
        );
    }
}
