<?php

namespace App\Domain\Attendance\Capture;

use App\Domain\Attendance\Boot\CaptureBoot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Verification photos: real mime check, size cap, GD re-encode (strips metadata, max 1024 px) into private storage. */
final class PunchPhoto
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    public const MAX_EDGE = 1024;

    /** @return string storage path on the attendance_private disk */
    public static function store(UploadedFile $file, int $tenantId): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['photo' => [$message]]);
        if (! $file->isValid() || ! is_file($file->getPathname())) {
            $fail('The photo could not be uploaded. Please try again.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            $fail('The photo is larger than 3 MB. Please take a smaller picture.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $loader = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp', default => null,
        };
        if (! $loader || ! function_exists($loader)) {
            $fail('The photo must be a JPEG, PNG or WebP image.');
        }
        $info = @getimagesize($file->getPathname());
        $image = $info ? @$loader($file->getPathname()) : false;
        if (! $image) {
            $fail('The photo could not be read. Please take another one.');
        }
        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($resized) {
                $image = $resized;
            }
        }
        ob_start();
        imagejpeg($image, null, 82);
        $bytes = ob_get_clean();
        $path = 'punch-photos/'.$tenantId.'/'.now()->format('Y/m').'/'.Str::uuid().'.jpg';
        Storage::disk(CaptureBoot::DISK)->put($path, $bytes);

        return $path;
    }
}
