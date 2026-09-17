<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class PaymentQuery
{
    private function __construct(
        public DateTimeInterface $paymentDate,
        public string $criterionType,
        public string $criterion,
        public string $senderBank,
        public string $receiverBank,
        public string $beneficiaryAccount,
        public string $amount,
        public bool $receiverIsBank,
    ) {
        $this->validate();
    }

    public static function byTrackingKey(
        DateTimeInterface $paymentDate,
        string $trackingKey,
        string $senderBank,
        string $receiverBank,
        string $beneficiaryAccount,
        string $amount,
        bool $receiverIsBank = false,
    ): self {
        return new self(
            paymentDate: $paymentDate,
            criterionType: 'T',
            criterion: trim($trackingKey),
            senderBank: trim($senderBank),
            receiverBank: trim($receiverBank),
            beneficiaryAccount: trim($beneficiaryAccount),
            amount: self::normalizeAmount($amount),
            receiverIsBank: $receiverIsBank,
        );
    }

    public static function byReference(
        DateTimeInterface $paymentDate,
        string $referenceNumber,
        string $senderBank,
        string $receiverBank,
        string $beneficiaryAccount,
        string $amount,
        bool $receiverIsBank = false,
    ): self {
        return new self(
            paymentDate: $paymentDate,
            criterionType: 'R',
            criterion: trim($referenceNumber),
            senderBank: trim($senderBank),
            receiverBank: trim($receiverBank),
            beneficiaryAccount: trim($beneficiaryAccount),
            amount: self::normalizeAmount($amount),
            receiverIsBank: $receiverIsBank,
        );
    }

    /** @internal */
    public function toBanxicoPayload(): array
    {
        return [
            'fecha' => $this->paymentDate->format('d-m-Y'),
            'tipoCriterio' => $this->criterionType,
            'criterio' => $this->criterion,
            'emisor' => $this->senderBank,
            'receptor' => $this->receiverBank,
            'cuenta' => $this->beneficiaryAccount,
            'monto' => $this->amount,
            'receptorParticipante' => $this->receiverIsBank ? 1 : 0,
            'tipoConsulta' => 1,
            'captcha' => '',
        ];
    }

    private function validate(): void
    {
        if ($this->criterion === '') {
            throw new InvalidArgumentException('Tracking key or reference number cannot be empty.');
        }

        if ($this->criterionType === 'T' && mb_strlen($this->criterion) > 30) {
            throw new InvalidArgumentException('Tracking key cannot exceed 30 characters.');
        }

        if ($this->criterionType === 'R' && mb_strlen($this->criterion) > 7) {
            throw new InvalidArgumentException('Reference number cannot exceed 7 characters.');
        }

        if (!preg_match('/^\d+$/', $this->senderBank)) {
            throw new InvalidArgumentException('Sender bank code must be numeric.');
        }

        if (!preg_match('/^\d+$/', $this->receiverBank)) {
            throw new InvalidArgumentException('Receiver bank code must be numeric.');
        }

        if ($this->beneficiaryAccount === '') {
            throw new InvalidArgumentException('Beneficiary account cannot be empty.');
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $this->amount)) {
            throw new InvalidArgumentException('Amount must be a positive decimal value with up to two decimal places.');
        }

        if ((float) $this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    private static function normalizeAmount(string $amount): string
    {
        return str_replace([',', ' '], '', trim($amount));
    }
}
