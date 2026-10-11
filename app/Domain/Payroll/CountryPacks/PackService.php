<?php

namespace App\Domain\Payroll\CountryPacks;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\PackResolver;
use App\Domain\Payroll\Models\{CountryPack, CountryPackVersion, PayrollGroup};
use App\Domain\Payroll\Support\{Checksum, PayrollSettings};
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pack and version lifecycle: draft -> published (immutable) -> retired; verification and enablement gates; resolution by date. */
final class PackService implements PackResolver
{
    public function __construct(private RuleValidator $validator) {}

    // ---- lifecycle ------------------------------------------------------------------------------------------------

    public function createPack(array $d): CountryPack
    {
        if (CountryPack::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'Code already exists.']);
        $p = CountryPack::create(['code' => $d['code'], 'country' => $d['country'], 'name' => $d['name'], 'handler' => $d['handler'] ?? DeclarativePack::HANDLER, 'status' => 'draft', 'description' => $d['description'] ?? null]);
        Audit::record('country_pack.created', 'payroll', $p->uuid);

        return $p;
    }

    public function createVersion(CountryPack $pack, array $d): CountryPackVersion
    {
        return DB::transaction(function () use ($pack, $d) {
            DB::table('tenants')->where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
            $d = $this->clean($d);
            $v = CountryPackVersion::create(['pack_id' => $pack->id, 'version' => (CountryPackVersion::where('pack_id', $pack->id)->max('version') ?? 0) + 1, 'status' => 'draft',
                'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null, 'tax_year_start' => $d['tax_year_start'] ?? '01-01', 'metadata' => $d['metadata'] ?? [],
                'calculation_rules' => $d['calculation_rules'] ?? null, 'validation_rules' => $d['validation_rules'] ?? null, 'identifiers' => $d['identifiers'] ?? null, 'declaration_schema' => $d['declaration_schema'] ?? null]);
            Audit::record('country_pack.version_created', 'payroll', $v->uuid, ['pack' => $pack->code, 'version' => $v->version]);

            return $v;
        });
    }

    public function updateDraft(CountryPackVersion $v, array $d): CountryPackVersion
    {
        if ($v->status !== 'draft') throw ValidationException::withMessages(['status' => 'Published versions are immutable. Create a new version.']);
        $v->update($this->clean($d)); Audit::record('country_pack.version_updated', 'payroll', $v->uuid);

        return $v;
    }

    private function clean(array $d): array
    {
        foreach (['calculation_rules', 'validation_rules', 'identifiers', 'declaration_schema'] as $k) {
            if (isset($d[$k])) { try { $d[$k] = $this->validator->normalize($d[$k]); } catch (\InvalidArgumentException $e) { throw ValidationException::withMessages([$k => $e->getMessage()]); } }
        }

        return $d;
    }

    /** @return array<int,string> */
    public function problems(CountryPackVersion $v): array
    {
        $p = $v->pack->handler === DeclarativePack::HANDLER ? $this->validator->problems($v->calculation_rules) : [];
        if (empty($v->metadata['source'] ?? null)) $p[] = 'metadata.source (the authority or document the rules come from) is required.';
        if (! array_key_exists('requires_expert_validation', $v->metadata ?? [])) $p[] = 'metadata.requires_expert_validation must be set explicitly.';

        return $p;
    }

    public function publish(CountryPackVersion $v, User $actor): CountryPackVersion
    {
        return DB::transaction(function () use ($v, $actor) {
            $v = CountryPackVersion::whereKey($v->id)->lockForUpdate()->firstOrFail();
            if ($v->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only a draft can be published.']);
            if ($problems = $this->problems($v)) throw ValidationException::withMessages(['rules' => $problems]);
            $from = substr((string) $v->effective_from, 0, 10);
            $others = CountryPackVersion::where('pack_id', $v->pack_id)->whereIn('status', ['published', 'retired'])->get();
            foreach ($others as $o) {
                $of = substr((string) $o->effective_from, 0, 10); $ot = $o->effective_to ? substr((string) $o->effective_to, 0, 10) : null;
                if ($of >= $from) throw ValidationException::withMessages(['effective_from' => 'A published version already starts on or after this date ('.$of.'). Versions must follow each other in time.']);
                if ($ot !== null && $ot >= $from) throw ValidationException::withMessages(['effective_from' => 'This overlaps a published version that ends '.$ot.'.']);
            }
            foreach ($others->where('status', 'published')->filter(fn ($o) => $o->effective_to === null) as $o) $o->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            $v->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id, 'checksum' => Checksum::of([$v->calculation_rules, $v->validation_rules, $v->identifiers, $v->declaration_schema, $from, $v->tax_year_start])]);
            Audit::record('country_pack.version_published', 'payroll', $v->uuid, ['checksum' => $v->checksum]);

            return $v;
        });
    }

    /** Expert verification marker. Independent from the publisher. */
    public function verify(CountryPackVersion $v, User $actor, string $note): CountryPackVersion
    {
        if ($v->status === 'draft') throw ValidationException::withMessages(['status' => 'Publish the version before verifying it.']);
        if ($v->published_by === $actor->id) throw ValidationException::withMessages(['verifier' => 'A different person than the publisher must verify the rules.']);
        $v->update(['verified_at' => now(), 'verified_by' => $actor->id, 'verification_note' => $note]);
        Audit::record('country_pack.version_verified', 'payroll', $v->uuid);

        return $v;
    }

    public function retire(CountryPackVersion $v, string $effectiveTo): CountryPackVersion
    {
        if ($v->status !== 'published') throw ValidationException::withMessages(['status' => 'Only a published version can be retired.']);
        if ($effectiveTo < substr((string) $v->effective_from, 0, 10)) throw ValidationException::withMessages(['effective_to' => 'Cannot end before the version starts.']);
        $v->update(['status' => 'retired', 'effective_to' => $effectiveTo]); Audit::record('country_pack.version_retired', 'payroll', $v->uuid);

        return $v;
    }

    public function enable(CountryPack $pack, User $actor): CountryPack
    {
        $versions = CountryPackVersion::where('pack_id', $pack->id)->where('status', 'published')->get();
        if ($versions->isEmpty()) throw ValidationException::withMessages(['status' => 'Publish at least one version before enabling the pack.']);
        if (PayrollSettings::get('calculation.require_pack_verification', true) && $versions->whereNotNull('verified_at')->isEmpty()) throw ValidationException::withMessages(['status' => 'No published version has been verified by an expert. Statutory rules must be validated before use.']);
        $pack->update(['status' => 'enabled', 'enabled_by' => $actor->id, 'enabled_at' => now()]); Audit::record('country_pack.enabled', 'payroll', $pack->uuid);

        return $pack;
    }

    public function disable(CountryPack $pack): CountryPack
    {
        $pack->update(['status' => 'disabled']); Audit::record('country_pack.disabled', 'payroll', $pack->uuid);

        return $pack;
    }

    // ---- resolution (PackResolver) -------------------------------------------------------------------------------------

    public function forGroup(int $payrollGroupId, string $date): ?CountryPackVersion
    {
        $packId = PayrollGroup::whereKey($payrollGroupId)->value('country_pack_id');
        if (! $packId) return null;
        $pack = CountryPack::find($packId);
        if (! $pack || $pack->status !== 'enabled') return null;
        $v = $this->version($packId, $date);

        return $v && (! PayrollSettings::get('calculation.require_pack_verification', true) || $v->verified_at) ? $v : null;
    }

    public function version(int $packId, string $date): ?CountryPackVersion
    {
        return CountryPackVersion::where('pack_id', $packId)->whereIn('status', ['published', 'retired'])->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('version')->first();
    }

    public function presentVersion(CountryPackVersion $v, bool $withRules = true): array
    {
        $d = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        return ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'effective_from' => $d($v->effective_from), 'effective_to' => $d($v->effective_to), 'tax_year_start' => $v->tax_year_start,
            'metadata' => $v->metadata, 'verified' => $v->verified_at !== null, 'verification_note' => $v->verification_note, 'published_at' => $v->published_at?->toIso8601String(), 'checksum' => $v->checksum,
            'requires_expert_validation' => (bool) ($v->metadata['requires_expert_validation'] ?? true), 'non_authoritative' => (bool) ($v->metadata['non_authoritative'] ?? false)]
            + ($withRules ? ['calculation_rules' => $v->calculation_rules, 'validation_rules' => $v->validation_rules, 'identifiers' => $v->identifiers, 'declaration_schema' => $v->declaration_schema] : []);
    }

    public function presentPack(CountryPack $p): array
    {
        return ['uuid' => $p->uuid, 'code' => $p->code, 'country' => $p->country, 'name' => $p->name, 'handler' => $p->handler, 'status' => $p->status, 'description' => $p->description,
            'versions' => $p->versions()->orderByDesc('version')->get()->map(fn ($v) => $this->presentVersion($v, false))->all()];
    }
}
