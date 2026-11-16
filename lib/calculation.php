<?php
declare(strict_types=1);

function amount_to_cents(string|float|int $value): int {
    $s = trim((string)$value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) throw new RuntimeException('Amount must have at most two decimal places.');
    [$whole, $fraction] = array_pad(explode('.', $s, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    $cents = ((int)$whole * 100) + (int)substr($fraction, 0, 2);
    if ($cents <= 0 || $cents > 100000000000) throw new RuntimeException('Enter an amount between 0.01 and 1,000,000,000.00.');
    return $cents;
}

function round_cents_from_pesos(float $pesos): int {
    if (!is_finite($pesos) || $pesos < 0) throw new RuntimeException('Invalid amount.');
    return (int)round($pesos * 100, 0, PHP_ROUND_HALF_UP);
}

function calculate_tax(int $grossCents, bool $vat, float $businessPercent, float $ewtPercent): array {
    if ($grossCents <= 0 || $grossCents > 100000000000) throw new RuntimeException('Invalid gross amount.');
    foreach ([$businessPercent, $ewtPercent] as $r) if (!is_finite($r) || $r < 0 || $r > 100) throw new RuntimeException('Invalid withholding rates.');
    if ($businessPercent + $ewtPercent > 100) throw new RuntimeException('Invalid withholding rates.');
    $grossPesos = $grossCents / 100;
    $base = round_cents_from_pesos($vat ? $grossPesos / 1.12 : $grossPesos);
    $basePesos = $base / 100;
    $business = round_cents_from_pesos($basePesos * ($businessPercent / 100));
    $ewt = round_cents_from_pesos($basePesos * ($ewtPercent / 100));
    return ['gross'=>$grossCents,'base'=>$base,'business'=>$business,'ewt'=>$ewt,'total'=>$business+$ewt,'net'=>$grossCents-$business-$ewt];
}

function reporting_period(string $date): array {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid date.');
    return ['from'=>$d->format('Y-m-01'),'to'=>$d->format('Y-m-t')];
}
