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

    private function validatePayment(PaymentQuery $query, CookieJar $cookies): void
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
                'form_params' => $query->toBanxicoPayload(),
            ]);
        } catch (GuzzleException $e) {
            throw $this->exception(ErrorCode::HttpRequestFailed, $e);
        }

        $body = $this->decode((string) $response->getBody());

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

        if (str_contains($body, self::CEP_NOT_AVAILABLE_MARKER)) {
            throw new CepNotAvailableException(
                ErrorCode::CepNotAvailable,
                $this->messages->get(ErrorCode::CepNotAvailable),
            );
        }
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
