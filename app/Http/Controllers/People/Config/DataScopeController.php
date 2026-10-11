<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\People\Config\DataScopeGrants;
use App\Domain\People\Models\UserDataScope;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class DataScopeController extends PeopleController
{
    public function __construct(private DataScopeGrants $grants) {}

    public function catalogue()
    {
        return $this->grants->catalogue();
    }

    public function index(Request $r)
    {
        return $this->grants->list($r->only(['user_id', 'kind']), $this->perPage($r));
    }

    public function store(Request $r)
    {
        $result = $this->grants->create($r->all());

        return response()->json($result, $result['created'] > 0 ? 201 : 200);
    }

    public function destroy(int $id)
    {
        $this->grants->delete(UserDataScope::query()->findOrFail($id));

        return response()->noContent();
    }
}
