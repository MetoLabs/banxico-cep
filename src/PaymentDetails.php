<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

use DOMDocument;
use DOMXPath;
use JsonSerializable;
use UnexpectedValueException;

/** Details reported by the SPEI status consultation; this is not a CEP certificate. */
final readonly class PaymentDetails implements JsonSerializable
{
    public function __construct(
        public string $referenceNumber,
        public string $trackingKey,
        public string $senderBank,
        public string $receiverBank,
        public string $status,
        public ?string $receivedAt,
        public ?string $processedAt,
        public ?string $beneficiaryAccount,
        public ?string $amount,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @internal @return list<self> */
    public static function fromHtml(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $tables = $xpath->query('//*[@id="consultaMISPEI"]//table');
        $payments = [];
        foreach ($tables as $table) {
            $fields = [];
            foreach ($xpath->query('.//tr', $table) as $row) {
                $cells = $xpath->query('./td', $row);
                if ($cells->length !== 2) {
                    continue;
                }
                $label = mb_strtolower(self::text($cells->item(0)->textContent), 'UTF-8');
                $fields[$label] = self::text($cells->item(1)->textContent);
            }
            // Ignore layout tables, but reject incomplete payment tables.
            if (!isset($fields['clave de rastreo'], $fields['estado del pago en banxico'])) {
                continue;
            }
            foreach (['número de referencia', 'clave de rastreo', 'institución emisora del pago',
                'institución receptora del pago', 'estado del pago en banxico'] as $required) {
                if (!isset($fields[$required]) || $fields[$required] === '') {
                    throw new UnexpectedValueException('Incomplete payment details.');
                }
            }
            $payments[] = new self(
                referenceNumber: $fields['número de referencia'],
                trackingKey: $fields['clave de rastreo'],
                senderBank: $fields['institución emisora del pago'],
                receiverBank: $fields['institución receptora del pago'],
                status: $fields['estado del pago en banxico'],
                receivedAt: $fields['fecha y hora de recepción'] ?? null,
                processedAt: $fields['fecha y hora de procesamiento'] ?? null,
                beneficiaryAccount: $fields['cuenta beneficiaria'] ?? null,
                amount: isset($fields['monto']) ? str_replace(',', '', $fields['monto']) : null,
            );
        }
        if ($payments === []) {
            throw new UnexpectedValueException('No recognized payment details in the response.');
        }

        return $payments;
    }

    private static function text(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value);
    }
}
