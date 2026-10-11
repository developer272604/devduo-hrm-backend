<?php

namespace App\Support;

final class RequestFingerprint
{
    public static function make(array $data): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $normalize($item);
            return $value;
        };
        return hash_hmac('sha256', json_encode($normalize($data), JSON_THROW_ON_ERROR), config('app.key'));
    }
}
