<?php

namespace App\Http\Controllers\Security;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrivacyRequestController extends Controller
{
    private const OPEN = ['submitted', 'in_review'];

    private function requests(Request $request): Builder
    {
        return DB::table('privacy_requests')->where('privacy_requests.tenant_id', $request->user()->tenant_id);
    }

    private function present(object $row, bool $review = false): array
    {
        $data = [
            'uuid' => $row->uuid,
            'kind' => $row->kind,
            'status' => $row->status,
            'version' => (int) $row->version,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'details' => Crypt::decryptString($row->encrypted_details),
            'outcome' => $row->encrypted_outcome !== null ? Crypt::decryptString($row->encrypted_outcome) : null,
        ];
        if (isset($row->has_file)) {
            $data['has_file'] = (bool) $row->has_file;
        }
        if ($review) {
            $data['requester'] = $row->requester_name;
        }

        return $data;
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request)
    {
        $rows = $this->requests($request)->where('requester_id', $request->user()->id)
            ->select('privacy_requests.*')
            ->selectSub(DB::table('privacy_request_files')->selectRaw('COUNT(*)')
                ->whereColumn('privacy_request_id', 'privacy_requests.id'), 'has_file')
            ->latest('id')->paginate(20)->through(fn ($row) => $this->present($row));

        return $this->json($rows->toArray());
    }

    public function create(Request $request)
    {
        $data = $request->validate(['request_key' => 'required|uuid',
            'kind' => 'required|in:access,correction,deletion', 'details' => 'required|string|max:3000']);
        $user = $request->user();

        return DB::transaction(function () use ($request, $user, $data) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $query = $this->requests($request)->where('requester_id', $user->id)->where('request_key', $data['request_key']);
            if ($existing = $query->first()) {
                abort_unless($existing->kind === $data['kind']
                    && Crypt::decryptString($existing->encrypted_details) === $data['details'], 409, 'This request key is already in use.');

                return $this->json($this->present($existing));
            }
            $uuid = (string) Str::uuid();
            DB::table('privacy_requests')->insert(['uuid' => $uuid, 'tenant_id' => $user->tenant_id, 'requester_id' => $user->id,
                'request_key' => $data['request_key'], 'kind' => $data['kind'], 'status' => 'submitted',
                'encrypted_details' => Crypt::encryptString($data['details']), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('privacy.request_submitted', 'security', 'privacy_request:'.$uuid, ['kind' => $data['kind']]);

            return $this->json($this->present($query->first()), 201);
        }, 3);
    }

    public function withdraw(Request $request, string $uuid)
    {
        $user = $request->user();

        return DB::transaction(function () use ($request, $user, $uuid) {
            $query = $this->requests($request)->where('requester_id', $user->id)->where('uuid', $uuid);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'withdrawn') {
                abort_unless(in_array($row->status, self::OPEN, true), 409, 'This request already has an outcome.');
                $query->update(['status' => 'withdrawn', 'version' => $row->version + 1, 'updated_at' => now()]);
                Audit::record('privacy.request_withdrawn', 'security', 'privacy_request:'.$uuid);
            }

            return $this->json($this->present($query->first()));
        }, 3);
    }

    private function authorizeReviewer(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->is_owner || app(DataScope::class)->hasTenantWide($user, 'security.privacy.manage'), 403);
    }

    public function queue(Request $request)
    {
        $this->authorizeReviewer($request);
        $filters = $request->validate(['status' => 'nullable|in:submitted,in_review,completed,declined,withdrawn']);
        $rows = $this->requests($request)->where('requester_id', '!=', $request->user()->id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->join('users', 'users.id', '=', 'privacy_requests.requester_id')
            ->whereColumn('users.tenant_id', 'privacy_requests.tenant_id')->latest('privacy_requests.id')
            ->paginate(20, ['privacy_requests.*', 'users.name as requester_name'])->through(fn ($row) => $this->present($row, true));
        if ($rows->count()) {
            Audit::record('privacy.queue_viewed', 'security', 'privacy_requests', ['count' => $rows->count()]);
        }

        return $this->json($rows->toArray());
    }

    public function download(Request $request, string $uuid)
    {
        $row = $this->requests($request)->where('requester_id', $request->user()->id)
            ->where('uuid', $uuid)->where('kind', 'access')->where('status', 'completed')
            ->join('privacy_request_files', 'privacy_request_files.privacy_request_id', '=', 'privacy_requests.id')
            ->first(['privacy_request_files.filename', 'privacy_request_files.encrypted_contents']);
        abort_unless($row, 404);
        $contents = Crypt::decryptString($row->encrypted_contents);
        Audit::record('privacy.response_downloaded', 'security', 'privacy_request:'.$uuid);

        return response()->streamDownload(static fn () => print($contents), $row->filename, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, string $uuid)
    {
        $this->authorizeReviewer($request);
        $data = $request->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:in_review,completed,declined',
            'outcome' => 'required_if:status,completed,declined|nullable|string|max:3000',
            'response_file' => 'nullable|file|mimes:pdf,txt,csv,json|extensions:pdf,txt,csv,json|max:2048']);
        $user = $request->user();

        return DB::transaction(function () use ($request, $uuid, $data, $user) {
            $query = $this->requests($request)->where('uuid', $uuid);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if((int) $row->requester_id === $user->id, 403);
            abort_unless((int) $row->version === (int) $data['version'] && in_array($row->status, self::OPEN, true)
                && $row->status !== $data['status'], 409, 'The request changed. Reload it before reviewing.');
            if ($file = $data['response_file'] ?? null) {
                if ($row->kind !== 'access' || $data['status'] !== 'completed') {
                    throw ValidationException::withMessages(['response_file' => 'Files are available for completed data access requests.']);
                }
                DB::table('privacy_request_files')->insert([
                    'privacy_request_id' => $row->id,
                    'filename' => 'privacy-response.'.strtolower($file->getClientOriginalExtension()),
                    'encrypted_contents' => Crypt::encryptString($file->get()),
                ]);
            }
            $query->update(['status' => $data['status'], 'version' => $row->version + 1, 'reviewer_id' => $user->id,
                'encrypted_outcome' => isset($data['outcome']) ? Crypt::encryptString($data['outcome']) : null, 'updated_at' => now()]);
            Audit::record('privacy.request_reviewed', 'security', 'privacy_request:'.$uuid, ['status' => $data['status'], 'response_file' => $file !== null]);

            return $this->json($this->present($query->first()));
        }, 3);
    }
}
