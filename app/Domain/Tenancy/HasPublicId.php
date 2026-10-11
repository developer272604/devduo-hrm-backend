<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Str;

/** Aggregate roots expose a UUID; sequential ids never leave the API boundary. */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function byUuid(string $uuid): static
    {
        return static::query()->where('uuid', $uuid)->firstOrFail();
    }
}
