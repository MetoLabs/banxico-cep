# Banxico CEP PHP Client

Small, framework-agnostic PHP client for querying SPEI payment details and downloading its Banxico CEP as PDF, XML, or ZIP.

The public API uses English names. Banxico-specific Spanish form field names stay internal to the package.

> This package talks to Banxico's public CEP website endpoints, not to a documented stable API. A change in the website can require a package update.

## Requirements

- PHP 8.2+
- Guzzle 7
- PHP extensions: DOM, JSON, mbstring

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
    beneficiaryAccount: '646000000000000000',
    amount: '1500.00',
);

$pdf = $client->download($query);

file_put_contents('cep.pdf', $pdf);
```

### Consult payment details without downloading PDF or XML

```php
$payments = $client->consult($query);

foreach ($payments as $payment) {
    echo $payment->status;             // e.g. Liquidado
    echo $payment->trackingKey;
    echo $payment->referenceNumber;
    echo $payment->senderBank;         // Institution name reported by Banxico
    echo $payment->receiverBank;
    echo $payment->beneficiaryAccount;
    echo $payment->amount;             // Decimal string, never converted to float
    echo $payment->receivedAt;         // Banxico's original date/time string
    echo $payment->processedAt;
    $data = $payment->toArray();
}

$json = json_encode($payments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

`consult()` initializes a session and submits `valida.do` with `tipoConsulta=0`.
It never calls a download endpoint. The result is a list because a reference may
match multiple payments. Optional fields omitted by Banxico are `null` and are
not filled from the submitted query. Unrecognized responses raise
`cep.invalid_response`; missing payments and rate limits retain their specific exceptions.

This retrieves **SPEI payment status details**, not the complete CEP certificate.
The HTML consultation does not expose payer/beneficiary names, RFCs, digital seals,
or proof of final accreditation. `Liquidado` must not be interpreted as CEP availability.
See [Banxico's consultation](https://www.banxico.org.mx/cep/).

### PEIBO sender and receiver from CLABE

PEIBO's institution code is `90732`. The beneficiary account is the full CLABE;
the receiving institution is resolved from its first three digits against
Banxico's `instituciones.do` catalogue for the payment date. The sender's account
number is not needed for this query.

```php
$date = new DateTimeImmutable('2026-09-01');
$clabe = '012000000000000000'; // Replace with the actual beneficiary CLABE
$query = PaymentQuery::byTrackingKey(
    paymentDate: $date,
    trackingKey: 'ACTUAL-SPEI-TRACKING-KEY',
    senderBank: '90732',
    receiverBank: $client->receiverBankFromClabe($clabe, $date),
    beneficiaryAccount: $clabe,
    amount: '1500.00',
);
$payments = $client->consult($query);
```

The resolver validates the 18-digit format and requires a unique catalogue match;
it does not validate the CLABE checksum. Do not simply prepend `40` or `90` to a
CLABE prefix: institution codes have different prefixes.

### Test a CSV export

```bash
php bin/consult-csv.php '/path/export.csv' spei_tracking_key tracking 1
php bin/consult-csv.php '/path/export.csv' reference reference 1
```

The arguments are the CSV path, the criterion column,
`tracking` or `reference`, and an optional row limit (default `1`). Output is one
JSON object per row; the command exits with a nonzero status if a row fails.
It uses `completed_at` as the payment date (assumed Mexico City local time),
`to_external_account_id` as the CLABE, `amount` as the amount, and PEIBO as sender.
Supply the correct payment date if your export uses another timezone.

For this PEIBO export, `tracking_id` is an internal identifier and is not used.
In `reference` mode, the utility validates that the selected column is numeric
and takes its **last seven digits**, preserving leading zeroes. For example,
`1234567890123456` becomes SPEI reference `0123456` and is submitted with
`tipoCriterio=R`. Shorter references are kept as supplied. This conversion is
specific to the CSV utility; `PaymentQuery::byReference()` still expects an
already prepared SPEI reference of at most seven digits.
The private CSV is not included in this repository.

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
    beneficiaryAccount: '646000000000000000',
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
    beneficiaryAccount: '646000000000000000',
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
4. For downloads, GET `descarga.do?formato=PDF|XML|ZIP`.

For `consult()`, step 2 uses `tipoConsulta=0` and the client parses the payment
tables from the HTML response instead of downloading a document.

This approach is based on the behavior demonstrated by `cuenca-mx/cep-python`, while the request/session details also take cues from `carlosupreme/cep-query-payment`.

## License

MIT.
