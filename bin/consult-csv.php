<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MetoLabs\Banxico\Cep\CepClient;
use MetoLabs\Banxico\Cep\PaymentQuery;

// Export references use their last seven digits as the SPEI reference.
if ($argc < 4 || !in_array($argv[3], ['tracking', 'reference'], true)) {
    fwrite(STDERR, "Usage: php bin/consult-csv.php <csv> <criterion-column> <tracking|reference> [limit=1]\n");
    exit(1);
}
$limit = filter_var($argv[4] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($limit === false || !is_readable($argv[1])) {
    fwrite(STDERR, "Provide a readable CSV and a positive row limit.\n");
    exit(1);
}
$handle = fopen($argv[1], 'r');
if ($handle === false) {
    exit(1);
}
$headers = fgetcsv($handle, escape: '');
if ($headers === false || array_diff([$argv[2], 'to_external_account_id', 'completed_at', 'amount'], $headers)) {
    fwrite(STDERR, "CSV is missing required columns.\n");
    fclose($handle);
    exit(1);
}
$client = new CepClient();
$failed = false;
for ($i = 0; $i < $limit && ($values = fgetcsv($handle, escape: '')) !== false; $i++) {
    try {
        if (count($values) !== count($headers)) {
            throw new InvalidArgumentException('CSV row does not match the header.');
        }
        $row = array_combine($headers, $values);
        $criterion = trim($row[$argv[2]]);
        if ($argv[3] === 'reference') {
            if (!preg_match('/^[0-9]+$/D', $criterion)) {
                throw new InvalidArgumentException('Export reference must contain only digits.');
            }
            // Keep this as a string to preserve leading zeroes.
            $criterion = substr($criterion, -7);
        }
        $pattern = $argv[3] === 'tracking' ? '/^[a-zA-Z0-9-]{1,30}$/D' : '/^[0-9]{1,7}$/D';
        if (!preg_match($pattern, $criterion)) {
            throw new InvalidArgumentException('Column does not contain a valid SPEI criterion; supply the actual tracking key (max 30 characters) or reference (max 7 digits).');
        }
        $dateText = substr($row['completed_at'], 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, new DateTimeZone('America/Mexico_City'));
        if ($date === false || $date->format('Y-m-d') !== $dateText) {
            throw new InvalidArgumentException('Invalid completed_at date.');
        }
        $clabe = $row['to_external_account_id'];
        $bank = $client->receiverBankFromClabe($clabe, $date);
        $factory = $argv[3] === 'tracking' ? 'byTrackingKey' : 'byReference';
        $query = PaymentQuery::$factory($date, $criterion, '90732', $bank, $clabe, $row['amount']);
        $result = ['row' => $i + 1, 'payments' => $client->consult($query)];
    } catch (Throwable $e) {
        $failed = true;
        $result = ['row' => $i + 1, 'error' => $e->getMessage()];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}
fclose($handle);
exit($failed ? 1 : 0);
