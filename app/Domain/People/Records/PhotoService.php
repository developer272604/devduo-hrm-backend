<?php

namespace App\Domain\People\Records;

use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Profile photos: validated, re-encoded to a metadata-free JPEG of at most 512 px, kept on the private disk. */
final class PhotoService
{
    public const MAX_SIDE = 512;

    private const MAX_PIXELS = 40_000_000;

    public function __construct(private FileInspector $inspector) {}

    public function store(Employee $employee, UploadedFile $file): Person
    {
        $facts = $this->inspector->inspectImage($file);
        $jpeg = $this->reencode($file->getRealPath(), $facts['mime']);
        $person = Person::query()->findOrFail($employee->person_id);
        $path = $employee->tenant_id.'/'.$person->uuid.'/photo-'.Str::lower(Str::random(32)).'.jpg';
        $disk = Storage::disk(DocumentService::DISK);
        $disk->put($path, $jpeg, ['visibility' => 'private']);

        $old = $person->photo_path;

        DB::transaction(function () use ($person, $path, $employee) {
            $person->photo_path = $path;
            $person->save();
            RecordLog::audit('photo_updated', $employee, 'photo');
            RecordLog::event('ProfilePhotoChanged', $employee, 'Profile photo updated.', 'employee', ['person', $person->id]);
        });
        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        return $person->refresh();
    }

    public function remove(Employee $employee): void
    {
        $person = Person::query()->findOrFail($employee->person_id);
        $old = $person->photo_path;
        DB::transaction(function () use ($person, $employee) {
            $person->photo_path = null;
            $person->save();
            RecordLog::audit('photo_removed', $employee, 'photo');
            RecordLog::event('ProfilePhotoChanged', $employee, 'Profile photo removed.', 'employee', ['person', $person->id]);
        });
        if ($old) {
            Storage::disk(DocumentService::DISK)->delete($old);
        }
    }

    public function stream(Employee $employee): StreamedResponse
    {
        $path = Person::query()->whereKey($employee->person_id)->value('photo_path');
        $disk = Storage::disk(DocumentService::DISK);
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, 'photo.jpg', ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300'], 'inline');
    }

    private function reencode(string $path, string $mime): string
    {
        $size = @getimagesize($path);
        if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => ['This image is too large or damaged. Try a different photo.']]);
        }
        $raw = (string) file_get_contents($path);
        $source = @imagecreatefromstring($raw);
        if (! $source) {
            throw ValidationException::withMessages(['file' => ['This image could not be read. Try a different photo.']]);
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $angle = match ($exif['Orientation'] ?? 1) {
                3 => 180, 6 => -90, 8 => 90, default => 0,
            };
            if ($angle !== 0 && ($rotated = imagerotate($source, $angle, 0))) {
                $source = $rotated;
            }
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // flatten transparency
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($canvas, null, 85);
        $out = (string) ob_get_clean();

        return $out;
    }
}
