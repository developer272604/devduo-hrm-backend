<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Helpers for the people-operations suites: temp storage roots, csv uploads, ad-hoc roles. */
trait OperationsFixtures
{
    protected string $opsRoot;

    protected function opsSetUp(): void
    {
        $this->opsRoot = sys_get_temp_dir().'/hrm-ops-'.uniqid();
        config(['filesystems.disks.hrm_imports.root' => $this->opsRoot.'/imports', 'filesystems.disks.hrm_exports.root' => $this->opsRoot.'/exports']);
        Storage::forgetDisk(['hrm_imports', 'hrm_exports']);
    }

    protected function opsTearDown(): void
    {
        $this->rrmdir($this->opsRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$dir/$f") ? $this->rrmdir("$dir/$f") : @unlink("$dir/$f");
            }
        }
        @rmdir($dir);
    }

    protected function csvFile(array $rows, string $name = 'people.csv'): UploadedFile
    {
        $out = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return UploadedFile::fake()->createWithContent($name, stream_get_contents($out));
    }

    protected function upload(array $rows, array $options = [], string $name = 'people.csv'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post('/api/v1/people/imports', ['file' => $this->csvFile($rows, $name), 'options' => $options]);
    }

    protected function roleUser(array $permissions, string $scope = 'tenant', ?string $email = null): User
    {
        $this->context($this->admin->tenant_id);
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'Test role', 'data_scope' => $scope, 'system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());
        $user = new User(['name' => 'Tester', 'email' => $email ?? 'u'.uniqid().'@alpha.test', 'password' => 'StrongPassword123']);
        $user->tenant_id = app(\App\Domain\Tenancy\TenantContext::class)->id;
        $user->save();
        $user->roles()->attach($role->id);

        return $user->refresh();
    }
}
