<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\CandidateService;
use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\CandidateImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CandidateImportController
{
    public function preview(Request $request)
    {
        $request->validate(['file' => 'required|file|max:1024']);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            abort_unless(is_array($headers), 422, 'CSV headers are required.');
            $headers = array_map(fn ($v) => trim((string) $v), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            $allowed = array_keys(CandidateService::rules());
            abort_if(count($headers) !== count(array_unique($headers)) || array_diff($headers, $allowed) || array_diff(array_diff($allowed, ['phone']), $headers), 422, 'Use the candidate CSV template headers. Phone is optional; duplicate and unknown columns are not allowed.');
            $rows = []; $preview = []; $seen = [];
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values === [null]) continue;
                abort_if(count($rows) >= 500, 422, 'Limit each import to 500 candidates.');
                abort_if(count($values) !== count($headers), 422, 'Each CSV row must have the same number of columns as the header.');
                $data = array_combine($headers, array_map(fn ($v) => trim((string) $v), $values));
                abort_unless(mb_check_encoding(implode('', $data), 'UTF-8'), 422, 'Upload a UTF-8 CSV file.');
                $errors = Validator::make($data, CandidateService::rules())->errors()->all();
                $hash = CandidateService::emailHash($data['email']);
                if (isset($seen[$hash]) || Candidate::where('email_hash', $hash)->exists()) $errors[] = 'Duplicate email: review separately; this row will not be imported.';
                $seen[$hash] = true;
                $rows[] = ['data' => $data, 'errors' => $errors];
                $preview[] = ['row' => count($rows), 'name' => $data['name'], 'email' => $data['email'], 'errors' => $errors];
            }
            abort_if(!$rows, 422, 'The CSV has no candidates.');
            $import = CandidateImport::create(['created_by' => $request->user()->id, 'rows' => $rows, 'expires_at' => now()->addDay()]);
            Audit::record('candidate_import.previewed', 'recruitment', $import->uuid, ['rows' => count($rows)]);
            return response()->json(['uuid' => $import->uuid, 'rows' => $preview, 'expires_at' => $import->expires_at], 201);
        } finally { fclose($handle); }
    }

    public function commit(Request $request, string $uuid, CandidateService $service)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        return DB::transaction(function () use ($request, $uuid, $service) {
            DB::table('tenants')->where('id', $request->user()->tenant_id)->lockForUpdate()->first();
            $import = CandidateImport::where('uuid', $uuid)->where('created_by', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($import->expires_at->isPast(), 410, 'Import expired. Upload the file again.');
            if ($import->status === 'committed') return ['uuid' => $import->uuid, 'rows' => $import->results];
            $results = [];
            foreach ($import->rows as $index => $row) {
                $errors = $row['errors'];
                if (!$errors) $errors = Validator::make($row['data'], CandidateService::rules())->errors()->all();
                if (!$errors && Candidate::where('email_hash', CandidateService::emailHash($row['data']['email']))->exists()) $errors[] = 'A matching candidate now exists; review separately.';
                $candidate = !$errors ? $service->create($request->user(), $row['data']) : null;
                $results[] = ['row' => $index + 1, 'status' => $candidate ? 'imported' : 'skipped', 'candidate_uuid' => $candidate?->uuid, 'errors' => $errors];
            }
            // Discard source PII immediately after commit; receipts expire with the batch.
            $import->update(['status' => 'committed', 'rows' => null, 'results' => $results]);
            Audit::record('candidate_import.committed', 'recruitment', $import->uuid, ['imported' => count(array_filter($results, fn ($r) => $r['status'] === 'imported')), 'rows' => count($results)]);
            return ['uuid' => $import->uuid, 'rows' => $results];
        });
    }
}
