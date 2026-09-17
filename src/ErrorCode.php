<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

enum ErrorCode: string
{
    case InvalidArgument = 'cep.invalid_argument';
    case PaymentNotFound = 'cep.payment_not_found';
    case CepNotAvailable = 'cep.not_available';
    case RateLimitExceeded = 'cep.rate_limit_exceeded';
    case HttpRequestFailed = 'cep.http_request_failed';
    case EmptyResponse = 'cep.empty_response';
    case InvalidResponse = 'cep.invalid_response';
    case FileWriteFailed = 'cep.file_write_failed';
}
