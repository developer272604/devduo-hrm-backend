<?php

namespace App\Domain\Payroll\Accounting;

use App\Domain\Payroll\Contracts\AccountingMapper;
use App\Domain\Payroll\Contracts\Dto\AccountingContext;
use App\Domain\Payroll\Contracts\Dto\GeneratedFile;
use App\Domain\Payroll\Contracts\Dto\JournalLine;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Neutral journal mapper (generic CSV; not any ledger product's format). Each mapped component posts debit_account / credit_account for
 * its amount, optionally split across cost dimensions by `allocation` percent rows. Journals balance by construction; unmapped components
 * (except informational/benefit kinds) block with a user-facing list.
 */
final class JournalCsvMapper implements AccountingMapper
{
    public const CODE = 'journal_csv';

    public function code(): string
    {
        return self::CODE;
    }

    public function map(AccountingContext $context): array
    {
        $lines = [];
        $unmapped = [];
        foreach ($context->components as $c) {
            if ($c['kind'] === 'informational') {
                continue;
            }
            $m = $this->resolve($context, $c);
            if (! $m || ! $m['debit_account'] || ! $m['credit_account']) {
                if ($c['kind'] !== 'benefit') {
                    $unmapped[$c['component_code']] = true;
                }
                continue;
            }
            $amount = Money::of($c['amount'], $context->currency);
            if ($amount->isZero()) {
                continue;
            }
            $alloc = $m['allocation'] ?? null;
            $parts = $alloc ? $amount->allocate(array_column($alloc, 'percent')) : [$amount];
            foreach ($parts as $i => $part) {
                if ($part->isZero()) {
                    continue;
                }
                $dims = array_filter(($c['dimensions'] ?? []) + (array) ($m['dimensions'] ?? []), fn ($v) => $v !== null);
                if ($alloc) {
                    $dims = array_replace($dims, array_filter((array) ($alloc[$i]['dimensions'] ?? []), fn ($v) => $v !== null));
                }
                $s = $part->toString(Money::exponent($context->currency));
                $lines[] = new JournalLine($m['debit_account'], $s, '0', $context->currency, $dims, $c['component_code'], $c['kind']);
                $lines[] = new JournalLine($m['credit_account'], '0', $s, $context->currency, $dims, $c['component_code'], $c['kind']);
            }
        }
        if ($unmapped) {
            throw ValidationException::withMessages(['mappings' => 'No accounting mapping for: '.implode(', ', array_keys($unmapped)).'. Add a mapping for each component (or a default for its kind).']);
        }

        return $lines;
    }

    private function resolve(AccountingContext $ctx, array $c): ?array
    {
        $date = $ctx->postingDate;
        $best = null;
        $bestScore = -1;
        foreach ($ctx->mappings as $m) {
            if (! ($m['active'] ?? true) || $m['effective_from'] > $date || ($m['effective_to'] && $m['effective_to'] < $date)) {
                continue;
            }
            if ($m['component_code'] ? $m['component_code'] !== $c['component_code'] : (($m['kind'] ?? null) !== $c['kind'])) {
                continue;
            }
            if (($m['group_code'] ?? null) && ($m['group_code'] !== ($c['group_code'] ?? null))) {
                continue;
            }
            $score = ($m['component_code'] ? 2 : 0) + ($m['group_code'] ?? null ? 1 : 0);
            if ($score > $bestScore) {
                $best = $m;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function export(array $lines, AccountingContext $context): GeneratedFile
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['posting_date', 'account', 'debit', 'credit', 'currency', 'component', 'memo', 'legal_entity', 'department', 'cost_center', 'location'], ',', '"', '\\');
        $debit = '0';
        $credit = '0';
        foreach ($lines as $l) {
            $d = $l->dimensions;
            fputcsv($out, array_map([$this, 'safe'], [$context->postingDate, $l->account, $l->debit, $l->credit, $l->currency, $l->componentCode, $l->memo, $d['legal_entity'] ?? '', $d['department'] ?? '', $d['cost_center'] ?? '', $d['location'] ?? '']), ',', '"', '\\');
            $debit = (string) Decimal::add($debit, $l->debit);
            $credit = (string) Decimal::add($credit, $l->credit);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return new GeneratedFile('journal-'.substr($context->runUuid, 0, 8).'-v'.$context->version.'.csv', 'text/csv', $csv, count($lines), $debit);
    }

    private function safe(mixed $v): string
    {
        $t = (string) $v;

        return $t !== '' && in_array($t[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-?\d+(\.\d+)?$/D', $t) ? "'".$t : $t;
    }
}
