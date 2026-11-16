<?php
declare(strict_types=1);
require __DIR__ . '/../lib/calculation.php';
function same(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
same(amount_to_cents('12.34'), 1234, 'amount parsing');
same(amount_to_cents('1'), 100, 'whole peso');
same(calculate_tax(11200, true, 5, 1), ['gross'=>11200,'base'=>10000,'business'=>500,'ewt'=>100,'total'=>600,'net'=>10600], 'VAT-base calculation');
same(calculate_tax(10000, false, 3, 2), ['gross'=>10000,'base'=>10000,'business'=>300,'ewt'=>200,'total'=>500,'net'=>9500], 'non-VAT calculation');
same(reporting_period('2026-02-15'), ['from'=>'2026-02-01','to'=>'2026-02-28'], 'reporting period');
try { amount_to_cents('0'); fwrite(STDERR, "FAIL zero amount accepted\n"); exit(1); }
catch (RuntimeException $e) {}
try { reporting_period('2026-02-30'); fwrite(STDERR, "FAIL invalid date accepted\n"); exit(1); }
catch (RuntimeException $e) {}
echo "PASS calculation smoke (mechanical baseline; not tax-law verification)\n";
