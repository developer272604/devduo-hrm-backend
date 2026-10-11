<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Audit\AuditChain;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

final class AuditSearchService
{
    private const MAX_EXPORT = 50000;

    public function filters(array $input): array
    {
        return validator($input, ['action' => 'nullable|string|max:100', 'module' => 'nullable|string|max:60', 'actor' => 'nullable|string|max:100', 'resource' => 'nullable|string|max:150',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'sensitive' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100'])->validate();
    }

    private function query(array $f): Builder
    {
        $q = AuditLog::query();
        foreach (['action', 'module', 'actor', 'resource'] as $field) {
            if (! empty($f[$field])) {
                $q->where($field, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f[$field]).'%');
            }
        }
        ! empty($f['from']) && $q->where('created_at', '>=', $f['from'].' 00:00:00');
        ! empty($f['to']) && $q->where('created_at', '<=', $f['to'].' 23:59:59');
        if (! empty($f['sensitive'])) {
            $q->where(fn ($w) => $w->where('module', 'security')->orWhere('action', 'like', '%viewed%')->orWhere('action', 'like', '%export%')->orWhere('action', 'like', '%reveal%'));
        }

        return $q;
    }

    public function search(array $input)
    {
        $f = $this->filters($input);

        return $this->query($f)->orderByDesc('id')->paginate($f['per_page'] ?? 25)->through(fn (AuditLog $r) => $this->present($r));
    }

    /** Streams CSV. The purpose is mandatory and is itself written to the audit chain before any data leaves. */
    public function export(array $input): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $purpose = validator($input, ['purpose' => 'required|string|min:10|max:500'])->validate()['purpose'];
        $f = $this->filters($input);
        $count = min($this->query($f)->count(), self::MAX_EXPORT);
        Audit::record('security.audit.exported', 'security', 'audit_log', ['purpose' => $purpose, 'filters' => array_diff_key($f, ['per_page' => 1]), 'rows' => $count]);
        $query = $this->query($f)->orderBy('id')->limit(self::MAX_EXPORT);
        $tenantId = app(\App\Domain\Tenancy\TenantContext::class)->id;

        return response()->streamDownload(function () use ($query, $tenantId) {
            // The body streams after the request middleware has cleared the tenant context.
            $context = app(\App\Domain\Tenancy\TenantContext::class);
            $previous = $context->id;
            $context->id = $tenantId;
            $out = fopen('php://output', 'w');
            fputcsv($out, ['sequence', 'time', 'actor', 'action', 'module', 'resource', 'ip_address', 'context', 'row_hash']);
            foreach ($query->cursor() as $r) {
                fputcsv($out, array_map([$this, 'safe'], [$r->sequence, $r->getRawOriginal('created_at'), $r->actor, $r->action, $r->module, $r->resource, $r->ip_address, json_encode($r->context), $r->row_hash]));
            }
            fclose($out);
            $context->id = $previous;
        }, 'audit-export-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function verify(): array
    {
        $result = AuditChain::verify();
        Audit::record($result['ok'] ? 'security.audit.verified' : 'security.audit.verification_failed', 'security', 'audit_log', ['checked' => $result['checked'], 'failures' => $result['failure_count']]);

        return $result;
    }

    /** Neutralise spreadsheet formula injection. */
    public function safe(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    private function present(AuditLog $r): array
    {
        return ['id' => $r->id, 'sequence' => $r->sequence, 'actor' => $r->actor, 'action' => $r->action, 'module' => $r->module, 'resource' => $r->resource, 'ip_address' => $r->ip_address,
            'context' => $r->context, 'created_at' => $r->created_at?->toIso8601String(), 'chained' => $r->sequence !== null];
    }
}
