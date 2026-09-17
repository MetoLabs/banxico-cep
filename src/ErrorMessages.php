<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

final readonly class ErrorMessages
{
    /** @var array<string, string> */
    private array $messages;

    /**
     * @param array<string|ErrorCode, string> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $messages = self::defaults();

        foreach ($overrides as $code => $message) {
            $key = $code instanceof ErrorCode ? $code->value : (string) $code;
            $messages[$key] = $message;
        }

        $this->messages = $messages;
    }

    public function get(ErrorCode $code): string
    {
        return $this->messages[$code->value] ?? $code->value;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            ErrorCode::InvalidArgument->value => 'One or more payment parameters are invalid.',
            ErrorCode::PaymentNotFound->value => 'The payment could not be found with the provided information.',
            ErrorCode::CepNotAvailable->value => 'The payment was found, but its CEP is not available yet.',
            ErrorCode::RateLimitExceeded->value => 'The Banxico CEP request limit has been exceeded.',
            ErrorCode::HttpRequestFailed->value => 'The request to Banxico CEP failed.',
            ErrorCode::EmptyResponse->value => 'Banxico returned an empty response.',
            ErrorCode::InvalidResponse->value => 'Banxico returned an unexpected response.',
            ErrorCode::FileWriteFailed->value => 'The CEP file could not be written.',
        ];
    }
}
