<?php

namespace App\Domain\Payroll\Payments;

use App\Domain\Payroll\Contracts\Dto\GeneratedFile;
use App\Domain\Payroll\Contracts\Dto\PaymentBatchData;
use App\Domain\Payroll\Contracts\PaymentFormat;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;

/**
 * Reference adapter: a neutral CSV (not any bank's format). Proves the adapter contract end to end; country / bank adapters are added by
 * workstream C next to this class and registered in PaymentFormatRegistry. Spreadsheet formula characters are neutralised in every cell.
 */
final class GenericCsvPaymentFormat implements PaymentFormat
{
    public const CODE = 'generic_csv';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Generic CSV (reference format)';
    }

    public function countries(): array
    {
        return [];
    }

    public function validate(PaymentBatchData $batch): array
    {
        $problems = [];
        $exp = Money::exponent($batch->currency);
        if ($batch->instructions === []) {
            $problems[] = 'The batch has no payment instructions.';
        }
        foreach ($batch->instructions as $i) {
            $who = $i['employee_number'] ?? $i['employee_uuid'] ?? '(unknown)';
            if (empty($i['beneficiary']['account_number'] ?? null) && empty($i['beneficiary']['iban'] ?? null)) {
                $problems[] = "Employee $who has no bank account number or IBAN.";
            }
            if (! preg_match('/^\d+(\.\d{1,'.max(1, $exp).'})?$/D', (string) ($i['amount'] ?? '')) || Decimal::isZero($i['amount'] ?? '0')) {
                $problems[] = "Employee $who has an invalid or zero payment amount.";
            }
        }

        return $problems;
    }

    public function generate(PaymentBatchData $batch): GeneratedFile
    {
        $rows = [['reference', 'employee_number', 'name', 'bank_name', 'account', 'routing', 'amount', 'currency', 'payment_date']];
        foreach ($batch->instructions as $i) {
            $b = $i['beneficiary'] ?? [];
            $rows[] = [$i['reference'] ?? '', $i['employee_number'] ?? '', $i['name'] ?? '', $b['bank_name'] ?? '', $b['iban'] ?? $b['account_number'] ?? '', $b['routing'] ?? $b['swift'] ?? '', $i['amount'], $batch->currency, $batch->paymentDate];
        }
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'safe'], $row), ',', '"', '\\');
        }
        rewind($out);
        $contents = (string) stream_get_contents($out);
        fclose($out);

        return new GeneratedFile('payment-'.substr($batch->batchUuid, 0, 8).'-v'.$batch->fileVersion.'.csv', 'text/csv', $contents, $batch->count(), $batch->total());
    }

    private function safe(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-?\d+(\.\d+)?$/D', $text) ? "'".$text : $text;
    }
}
