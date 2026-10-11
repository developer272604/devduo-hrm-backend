<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\Candidate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CandidateService
{
    public static function rules(): array
    {
        return ['name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:254', 'phone' => 'nullable|string|max:40', 'source' => 'required|string|max:80', 'privacy_notice_version' => 'required|string|max:80', 'consent_reference' => 'required|string|max:2000', 'consented_at' => 'required|date|before_or_equal:now', 'retention_until' => 'required|date_format:Y-m-d|after:today|before_or_equal:'.now('UTC')->addYears(2)->toDateString()];
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), config('app.key'));
    }

    public function create(User $actor, array $input): Candidate
    {
        abort_unless($actor->allows('recruitment.manage'), 403);
        $data = Validator::make($input, self::rules())->validate();
        $data['email'] = strtolower(trim($data['email']));
        $data['email_hash'] = self::emailHash($data['email']);
        $data['consented_at'] = CarbonImmutable::parse($data['consented_at'])->utc()->format('Y-m-d H:i:s');
        return DB::transaction(function () use ($actor, $data) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            abort_if(Candidate::where('email_hash', $data['email_hash'])->exists(), 409, 'A matching candidate exists. An authorized recruiter must review the duplicate; no automatic merge was performed.');
            $candidate = Candidate::create($data + ['created_by' => $actor->id]);
            Audit::record('candidate.created', 'recruitment', $candidate->uuid);
            return $candidate;
        });
    }
}
