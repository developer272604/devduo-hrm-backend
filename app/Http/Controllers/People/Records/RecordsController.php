<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\People\Records\RecordAccess;
use App\Http\Controllers\People\PeopleController;

/** Base for the sensitive-records controllers. Authorization lives in RecordAccess / FieldAccess, never in the UI. */
abstract class RecordsController extends PeopleController
{
    protected function rec(): RecordAccess
    {
        return app(RecordAccess::class);
    }
}
