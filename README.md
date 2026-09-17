# Banxico CEP PHP Client

Small, framework-agnostic PHP client for validating a SPEI payment and downloading its Banxico CEP as PDF, XML, or ZIP.

The public API uses English names. Banxico-specific Spanish form field names stay internal to the package.

> This package talks to Banxico's public CEP website endpoints, not to a documented stable API. A change in the website can require a package update.

## Requirements

- PHP 8.2+
- Guzzle 7

## Installation

For local development using a path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../banxico-cep"
        }
    ]
}
```

Then:

```bash
composer require metolabs/banxico-cep:@dev
```

Or publish the package to your preferred Composer repository and require it normally.

## Basic usage

```php
use DateTimeImmutable;
use MetoLabs\Banxico\Cep\CepClient;
use MetoLabs\Banxico\Cep\PaymentQuery;

$client = new CepClient();

$query = PaymentQuery::byTrackingKey(
    paymentDate: new DateTimeImmutable('2026-09-16'),
    trackingKey: 'YOUR-TRACKING-KEY',
    senderBank: '40012',
    receiverBank: '90646',
    beneficiaryAccount: '646180157034181234',
    amount: '1500.00',
);

$pdf = $client->download($query);

file_put_contents('cep.pdf', $pdf);
```

### Download directly to a file

```php
$client->downloadTo($query, storage_path('app/cep.pdf'));
```

### XML or ZIP

```php
use MetoLabs\Banxico\Cep\CepFormat;

$xml = $client->download($query, CepFormat::XML);
$zip = $client->download($query, CepFormat::ZIP);
```

### Search by reference number

```php
$query = PaymentQuery::byReference(
    paymentDate: new DateTimeImmutable('2026-09-16'),
    referenceNumber: '1234567',
    senderBank: '40012',
    receiverBank: '90646',
    beneficiaryAccount: '646180157034181234',
    amount: '1500.00',
);
```

### Payments where the receiver is a bank

Banxico uses `receptorParticipante = 1` for applicable payment types. Set:

```php
$query = PaymentQuery::byTrackingKey(
    paymentDate: new DateTimeImmutable('2026-09-16'),
    trackingKey: 'YOUR-TRACKING-KEY',
    senderBank: '40012',
    receiverBank: '90646',
    beneficiaryAccount: '646180157034181234',
    amount: '1500.00',
    receiverIsBank: true,
);
```

## Error handling

Every package exception exposes a stable `ErrorCode` in addition to its English message.

```php
use MetoLabs\Banxico\Cep\Exception\CepException;

try {
    $pdf = $client->download($query);
} catch (CepException $e) {
    // e.g. "cep.payment_not_found"
    $translationKey = $e->errorCode->value;

    // Laravel example:
    $message = __($translationKey);
}
```

Available error codes:

```text
cep.invalid_argument
cep.payment_not_found
cep.not_available
cep.rate_limit_exceeded
cep.http_request_failed
cep.empty_response
cep.invalid_response
cep.file_write_failed
```

Specific exceptions are also available:

```php
use MetoLabs\Banxico\Cep\Exception\CepNotAvailableException;
use MetoLabs\Banxico\Cep\Exception\PaymentNotFoundException;
use MetoLabs\Banxico\Cep\Exception\RateLimitExceededException;
```

## Custom error messages

The package defaults to English, but messages can be overridden without changing exception classes or error codes:

```php
use MetoLabs\Banxico\Cep\CepClient;
use MetoLabs\Banxico\Cep\ErrorCode;
use MetoLabs\Banxico\Cep\ErrorMessages;

$messages = new ErrorMessages([
    ErrorCode::PaymentNotFound->value => 'My custom message.',
    ErrorCode::CepNotAvailable->value => 'My other custom message.',
]);

$client = new CepClient(messages: $messages);
```

For Laravel, using the stable error code as a translation key is usually cleaner than overriding the library messages.

Example `lang/es/cep.php` alternative mapping if you prefer nested Laravel keys:

```php
return [
    'payment_not_found' => 'No se encontró el pago.',
    'not_available' => 'El CEP todavía no está disponible.',
];
```

Then map `$e->errorCode` however you prefer in your application layer.

## Custom HTTP client

```php
use GuzzleHttp\Client;
use MetoLabs\Banxico\Cep\CepClient;

$http = new Client([
    'base_uri' => CepClient::DEFAULT_BASE_URL,
    'timeout' => 60,
]);

$client = new CepClient(http: $http, timeout: 60);
```

## Testing

```bash
composer install
composer test
```

## Design

The client follows the CEP session flow:

1. Open the CEP page to initialize cookies.
2. POST payment data to `valida.do`.
3. Keep the same cookie jar/session.
4. GET `descarga.do?formato=PDF|XML|ZIP`.

This approach is based on the behavior demonstrated by `cuenca-mx/cep-python`, while the request/session details also take cues from `carlosupreme/cep-query-payment`.

## License

MIT.
