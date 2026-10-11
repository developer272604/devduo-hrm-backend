<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Imports\ImportService;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImportLeaveTest extends AccrualTestCase
{
    use RefreshDatabase;

    private function upload(string $kind, string $csv)
    {
        return $this->postJson('/api/v1/leave/imports', ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent('data.csv', $csv)]);
    }

    private function runImport(string $kind, string $csv, array $mapping = []): array
    {
        $id = $this->upload($kind, $csv)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/leave/imports/$id/mapping", ['mapping' => $mapping ?: $this->getJson("/api/v1/leave/imports/$id")->json('data.mapping')])->assertOk();
        $v = $this->postJson("/api/v1/leave/imports/$id/validate")->assertOk()->json('data');

        return [$id, $v];
    }

    public function test_opening_balance_import_preview_errors_commit_and_never_overwrites(): void
    {
        Storage::fake(ImportService::DISK);
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $e = $this->hire('Asha Rao');
        $this->signIn($this->admin);
        $csv = "employee_number,leave_type_code,units,as_of\n{$e->employee_number},AL,12.5,2026-01-01\nNOPE,AL,3,2026-01-01\n{$e->employee_number},XX,3,2026-01-01\n{$e->employee_number},AL,abc,2026-01-01\n";
        [$id, $v] = $this->runImport('opening_balance', $csv);
        $this->assertSame(1, $v['counts']['valid']);
        $this->assertSame(3, $v['counts']['errors']);
        $this->getJson("/api/v1/leave/imports/$id/rows?status=error")->assertOk()->assertJsonCount(3, 'data');
        $this->get("/api/v1/leave/imports/$id/errors")->assertOk();

        $this->postJson("/api/v1/leave/imports/$id/commit")->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $account = LeaveAccount::query()->where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('12.5000', app(BalanceService::class)->balance($account, '2026-10-07')->opening);
        $this->assertSame('completed_with_errors', $this->getJson("/api/v1/leave/imports/$id")->json('data.status'));

        // same value again => duplicate, different value => error, ledger untouched either way
        [$id2, $v2] = $this->runImport('opening_balance', "employee_number,leave_type_code,units,as_of\n{$e->employee_number},AL,12.5,2026-01-01\n");
        $this->assertSame(1, $v2['counts']['duplicates']);
        [$id3, $v3] = $this->runImport('opening_balance', "employee_number,leave_type_code,units,as_of\n{$e->employee_number},AL,20,2026-01-01\n");
        $this->assertSame(1, $v3['counts']['errors']);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
        // permission: a plain member cannot import
        $this->signIn($this->userWithRole('Team member', 'tm@alpha.test'))->getJson('/api/v1/leave/imports')->assertForbidden();
    }

    public function test_historical_requests_import_creates_approved_requests_and_consumption(): void
    {
        Storage::fake(ImportService::DISK);
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '10']]);
        $e = $this->hire('Asha Rao');
        $this->signIn($this->admin);
        [$oid] = $this->runImport('opening_balance', "employee_number,leave_type_code,units,as_of
{$e->employee_number},AL,10,2026-01-01
");
        $this->postJson("/api/v1/leave/imports/$oid/commit")->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $csv = "employee_number,leave_type_code,start_date,end_date,units,reason\n{$e->employee_number},AL,2026-03-02,2026-03-04,2.5,Trip\n{$e->employee_number},AL,2026-03-09,2026-03-08,1,bad\n";
        $id = $this->upload('historical_requests', $csv)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/leave/imports/$id/mapping", ['mapping' => $this->getJson("/api/v1/leave/imports/$id")->json('data.mapping')])->assertOk();
        $v = $this->postJson("/api/v1/leave/imports/$id/validate")->assertOk()->json('data');
        $this->assertSame([1, 1], [$v['counts']['valid'], $v['counts']['errors']]);
        $this->postJson("/api/v1/leave/imports/$id/commit")->assertStatus(202);
        $this->context($this->admin->tenant_id);
        $request = LeaveRequest::query()->firstOrFail();
        $this->assertSame('approved', $request->status);
        $this->assertSame('2.5000', \App\Domain\Leave\Support\Units::normalize($request->getRawOriginal('charged_units')));
        $account = LeaveAccount::query()->where('employee_id', $e->id)->firstOrFail();
        $b = app(BalanceService::class)->balance($account, '2026-10-07');
        $this->assertSame('2.5000', $b->consumed);
        $this->assertSame('7.5000', $b->available);
        // re-import is a duplicate request, not a second deduction
        $id2 = $this->upload('historical_requests', "employee_number,leave_type_code,start_date,end_date,units\n{$e->employee_number},AL,2026-03-02,2026-03-04,2.5\n")->json('data.id');
        $this->putJson("/api/v1/leave/imports/$id2/mapping", ['mapping' => $this->getJson("/api/v1/leave/imports/$id2")->json('data.mapping')]);
        $this->assertSame(1, $this->postJson("/api/v1/leave/imports/$id2/validate")->json('data.counts.duplicates'));
    }
}
