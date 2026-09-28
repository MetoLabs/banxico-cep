<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep\Tests;

use DateTimeImmutable;
use MetoLabs\Banxico\Cep\PaymentQuery;
use PHPUnit\Framework\TestCase;

final class PaymentQueryTest extends TestCase
{
    public function test_it_builds_the_expected_banxico_payload(): void
    {
        $query = PaymentQuery::byTrackingKey(
            paymentDate: new DateTimeImmutable('2000-01-02'),
            trackingKey: 'TRACKING-123',
            senderBank: '40012',
            receiverBank: '90646',
            beneficiaryAccount: '646000000000000000',
            amount: '1,500.00',
        );

        self::assertSame([
            'fecha' => '02-01-2000',
            'tipoCriterio' => 'T',
            'criterio' => 'TRACKING-123',
            'emisor' => '40012',
            'receptor' => '90646',
            'cuenta' => '646000000000000000',
            'monto' => '1500.00',
            'receptorParticipante' => 0,
            'tipoConsulta' => 1,
            'captcha' => '',
        ], $query->toBanxicoPayload());
    }
}
