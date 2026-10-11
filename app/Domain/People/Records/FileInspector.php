<?php

namespace App\Domain\People\Records;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Safe-upload gate. Never trusts the client: the extension must be allow-listed, the name must not hide an executable
 * behind a double extension, and the REAL content type (finfo, not the extension or the browser header) must match.
 */
final class FileInspector
{
    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    /** extension => detected content types we accept for it. */
    private const DOCUMENT_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-unknown', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-unknown', 'application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream', 'application/x-zip-compressed'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream', 'application/x-zip-compressed'],
    ];

    /** Canonical content type stored (and later served) for each extension. */
    private const CANONICAL = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Extensions that must never appear anywhere in a file name. */
    private const DANGEROUS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'mjs', 'jar', 'vbs', 'vbe', 'wsf', 'ps1',
        'sh', 'bash', 'py', 'pl', 'rb', 'cgi', 'asp', 'aspx', 'jsp', 'html', 'htm', 'xhtml', 'svg', 'hta', 'lnk', 'swf', 'htaccess'];

    public static function allowedExtensions(): array
    {
        return array_keys(self::DOCUMENT_TYPES);
    }

    /** @return array{mime:string,extension:string,size:int,name:string,sha256:string} validated facts about the upload */
    public function inspectDocument(UploadedFile $file): array
    {
        $this->assertUsable($file, self::MAX_DOCUMENT_BYTES, '10 MB');
        $name = $this->assertSafeName($file);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::DOCUMENT_TYPES[$extension])) {
            $this->fail('This file type is not allowed. Upload a PDF, JPG, PNG, WebP, Word (doc, docx) or Excel (xls, xlsx) file.');
        }
        $path = $file->getRealPath();
        $detected = $this->detect($path);
        if (! in_array($detected, self::DOCUMENT_TYPES[$extension], true)) {
            $this->fail('The file content does not match its extension, so it was rejected.');
        }
        $this->assertContainer($extension, $path);

        return ['mime' => self::CANONICAL[$extension], 'extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'size' => (int) $file->getSize(), 'name' => $name, 'sha256' => hash_file('sha256', $path)];
    }

    /** @return array{mime:string,extension:string,size:int,name:string} for profile photos (jpeg, png, webp only) */
    public function inspectImage(UploadedFile $file, int $maxBytes = self::MAX_PHOTO_BYTES): array
    {
        $this->assertUsable($file, $maxBytes, '5 MB');
        $name = $this->assertSafeName($file);
        $detected = $this->detect($file->getRealPath());
        if (! isset(self::IMAGE_TYPES[$detected])) {
            $this->fail('Upload a JPG, PNG or WebP image.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || (self::CANONICAL[$extension] ?? null) !== $detected) {
            $this->fail('The file content does not match its extension, so it was rejected.');
        }

        return ['mime' => $detected, 'extension' => self::IMAGE_TYPES[$detected], 'size' => (int) $file->getSize(), 'name' => $name];
    }

    /** A display-safe name: no path parts, no control characters, bounded length. */
    public static function displayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name) ?? '';

        return mb_substr(trim($name) !== '' ? trim($name) : 'document', 0, 200);
    }

    private function assertUsable(UploadedFile $file, int $maxBytes, string $limit): void
    {
        if (! $file->isValid() || ! is_file((string) $file->getRealPath())) {
            $this->fail('The file could not be uploaded. Try again with a smaller file.');
        }
        if ((int) $file->getSize() <= 0) {
            $this->fail('The file is empty.');
        }
        if ((int) $file->getSize() > $maxBytes) {
            $this->fail("The file is larger than $limit.");
        }
    }

    private function assertSafeName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();
        if ($name === '' || preg_match('/[\x00-\x1F]/', $name) || mb_strlen($name) > 255) {
            $this->fail('The file name is not valid.');
        }
        $parts = explode('.', strtolower(str_replace('\\', '/', basename(str_replace('\\', '/', $name)))));
        if (count($parts) < 2) {
            $this->fail('The file needs an extension such as .pdf or .jpg.');
        }
        foreach (array_slice($parts, 1) as $segment) {
            if (in_array(trim($segment), self::DANGEROUS, true)) {
                $this->fail('Files with executable or double extensions are not allowed.');
            }
        }

        return self::displayName($name);
    }

    private function detect(string $path): string
    {
        // Sniff the leading bytes (libmagic needs only the head); streaming also avoids locked temp files on Windows.
        $handle = @fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, 262144) : '';
        $handle && fclose($handle);

        return (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
    }

    /** Office files: confirm the container really is what the extension claims (ZIP with word/ or xl/, or OLE2 header). */
    private function assertContainer(string $extension, string $path): void
    {
        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $zip = new \ZipArchive;
            $needle = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $ok = $zip->open($path, \ZipArchive::RDONLY) === true && $zip->locateName($needle) !== false;
            $zip->close();
            if (! $ok) {
                $this->fail('The file is not a valid Office document.');
            }
        } elseif (in_array($extension, ['doc', 'xls'], true)) {
            $handle = fopen($path, 'rb');
            $magic = $handle ? fread($handle, 8) : '';
            $handle && fclose($handle);
            if ($magic !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                $this->fail('The file is not a valid Office document.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
