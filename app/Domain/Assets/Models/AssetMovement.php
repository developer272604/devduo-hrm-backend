<?php
namespace App\Domain\Assets\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class AssetMovement extends TenantRecord { use HasPublicId; }
