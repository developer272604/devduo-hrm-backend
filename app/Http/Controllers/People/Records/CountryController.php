<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\CountryConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CountryController extends RecordsController
{
    public function index(CountryConfig $countries)
    {
        return ['data' => $countries->all()];
    }

    public function show(string $code, CountryConfig $countries)
    {
        return $this->pack($this->code($code, $countries), $countries);
    }

    public function update(Request $r, string $code, CountryConfig $countries)
    {
        $code = CountryConfig::normalizeCode($code);
        abort_unless(CountryConfig::validCode($code), 422, 'Use a two-letter country code such as IN or GB.');
        $row = $countries->override($code);
        $isNew = $countries->defaultPack($code) === null && ! $row;
        $data = $r->validate([
            'enabled' => 'sometimes|boolean', 'name' => [$isNew ? 'required' : 'sometimes', 'nullable', 'string', 'max:120'], 'currency' => 'sometimes|nullable|string|size:3|alpha',
            'timezone' => 'sometimes|nullable|timezone:all', 'locale' => ['sometimes', 'nullable', 'regex:/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/'], 'date_format' => 'sometimes|nullable|string|max:30',
            'number_format' => 'sometimes|nullable|string|max:30', 'phone_code' => ['sometimes', 'nullable', 'regex:/^\+\d{1,4}$/'], 'name_format' => 'sometimes|nullable|string|max:60',
            'working_language' => 'sometimes|nullable|string|max:10',
            'address_format' => 'sometimes|nullable|array|max:30', 'identifier_types' => 'sometimes|nullable|array|max:60', 'bank_schema' => 'sometimes|nullable|array|max:30',
            'employment_fields' => 'sometimes|nullable|array|max:60', 'statutory_fields' => 'sometimes|nullable|array|max:60',
        ]);
        if ($errors = CountryConfig::structureErrors($data)) {
            throw ValidationException::withMessages($errors);
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        DB::transaction(function () use ($code, $data, $row) {
            $row ??= new CountryConfiguration(['country' => $code, 'enabled' => true]);
            $row->fill($data)->save();
            Audit::record('updated', 'people', 'country:'.$code, ['fields' => array_keys($data)]);
        });

        return $this->pack($code, new CountryConfig);
    }

    /** Removes the tenant override so the shipped pack (or the generic fallback) applies again. */
    public function destroy(string $code, CountryConfig $countries)
    {
        $code = CountryConfig::normalizeCode($code);
        $row = $countries->override($code);
        abort_unless($row, 404);
        $row->delete();
        Audit::record('reset', 'people', 'country:'.$code, []);

        return response()->noContent();
    }

    private function code(string $code, CountryConfig $countries): string
    {
        $code = CountryConfig::normalizeCode($code);
        abort_unless(CountryConfig::validCode($code) && $countries->known($code), 404, 'This country is not configured.');

        return $code;
    }

    private function pack(string $code, CountryConfig $countries): array
    {
        return $countries->for($code) + ['classifications' => Classification::ALL];
    }
}
