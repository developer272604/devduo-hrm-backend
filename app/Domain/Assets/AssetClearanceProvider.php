<?php
namespace App\Domain\Assets;
use App\Domain\Assets\Models\Asset;
use App\Domain\Lifecycle\Contracts\ClearanceProvider;
final class AssetClearanceProvider implements ClearanceProvider {
    public function key(): string { return 'assets'; }
    public function blockers(int $employeeId): array {
        $count = Asset::where('employee_id', $employeeId)->whereIn('status', ['assigned', 'lost'])->count();
        return $count ? ["{$count} assigned or lost asset(s) need custody resolution before exit clearance."] : [];
    }
}
