<?php

declare(strict_types=1);

namespace App\Domain\Files;

use Normalizer;

// Structural inspection of ADR 0019 D03 (inspection=STRUCTURAL; +AV only when clamd is configured). The declared MIME
// type of the client is ignored: the verdict comes from the final extension, the real MIME (finfo) and the magic bytes.
//
//   .pdf           application/pdf  "%PDF-"                rejected when encrypted or with active content
//   .jpg/.jpeg     image/jpeg       FF D8 FF               GD decode + dimension limits + RE-ENCODE (EXIF/GPS dropped)
//   .png           image/png        89 50 4E 47 0D 0A 1A 0A  idem
//   .webp          image/webp       "RIFF....WEBP"         idem
//
// Everything else is FILE_TYPE_NOT_ALLOWED: executables/scripts (MZ, ELF, Mach-O, #!, <?php), HTML/SVG/XML, archives
// (zip/OOXML/ODF, rar, 7z, gz, tar), HEIC, TIFF, and any double extension whose interior segment is dangerous.
final class FileInspector
{
    public const INSPECTION_STRUCTURAL = 'STRUCTURAL';
    public const INSPECTION_STRUCTURAL_AV = 'STRUCTURAL+AV';

    /** extension => real MIME */
    public const ALLOWED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public const DANGEROUS_SEGMENTS = [
        'exe', 'dll', 'bat', 'cmd', 'com', 'cpl', 'msi', 'msp', 'scr', 'pif', 'hta', 'msc', 'jar', 'apk', 'app', 'ps1', 'psm1',
        'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'sh', 'bash', 'zsh', 'csh', 'py', 'pl', 'rb', 'cgi', 'php', 'php3', 'php4', 'php5',
        'php7', 'phtml', 'phar', 'asp', 'aspx', 'jsp', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'xml', 'lnk', 'reg', 'inf', 'so', 'dylib',
    ];

    private const WINDOWS_RESERVED = '/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/i';

    // Reason codes carried by file.security_rejected (never the name or the content).
    public const R_EXTENSION = 'EXTENSION_NOT_ALLOWED';
    public const R_DOUBLE_EXTENSION = 'DOUBLE_EXTENSION';
    public const R_EXECUTABLE = 'EXECUTABLE_CONTENT';
    public const R_MARKUP = 'MARKUP_CONTENT';
    public const R_ARCHIVE = 'ARCHIVE_CONTENT';
    public const R_SIGNATURE = 'SIGNATURE_MISMATCH';
    public const R_PDF_ENCRYPTED = 'PDF_ENCRYPTED';
    public const R_PDF_ACTIVE = 'PDF_ACTIVE_CONTENT';
    public const R_IMAGE_DECODE = 'IMAGE_DECODE_FAILED';
    public const R_IMAGE_TOO_LARGE = 'IMAGE_TOO_LARGE';
    public const R_AV = 'AV_DETECTED';
    public const R_CUSTODY = 'CUSTODY_FAILED';
    public const R_CUSTODY_LOST = 'CUSTODY_LOST';

    /** Reasons reported as FILE_CONTENT_REJECTED (an allowed type whose content is refused); the rest are FILE_TYPE_NOT_ALLOWED. */
    public const CONTENT_REASONS = [self::R_PDF_ENCRYPTED, self::R_PDF_ACTIVE, self::R_IMAGE_DECODE, self::R_IMAGE_TOO_LARGE, self::R_AV];

    public function __construct(private int $maxPixels = 40000000, private int $maxSide = 10000)
    {
    }

    /**
     * D03 "Nome do ficheiro": NFC, path components / control characters / \ / : * ? " < > | removed, consecutive spaces
     * collapsed, Windows reserved stems neutralised, stem limited to 150 characters plus the extension.
     */
    public static function sanitizeName(mixed $name): string
    {
        $value = is_string($name) ? $name : '';
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: '';
        }
        $value = str_replace('\\', '/', $value);
        $value = basename('/' . $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/[\\\\\/:*?"<>|]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '', " .\t");
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
            throw new FilesError(FilesReason::FILE_NAME_INVALID, ['field' => 'file']);
        }
        $dot = strrpos($value, '.');
        $stem = $dot === false ? $value : substr($value, 0, $dot);
        $extension = $dot === false ? '' : strtolower(substr($value, $dot + 1));
        $stem = trim($stem, " .");
        if ($stem === '' || preg_match(self::WINDOWS_RESERVED, explode('.', $stem)[0]) === 1) {
            $stem = 'ficheiro' . ($stem === '' ? '' : '-' . $stem);
        }
        $stem = rtrim(mb_substr($stem, 0, 150), ' .');
        return $extension === '' ? $stem : $stem . '.' . $extension;
    }

    /**
     * Type verdict for a sanitized name and its bytes.
     * @return array{ok: bool, reason: ?string, extension: string, mime: string}
     */
    public function verdict(string $name, string $bytes): array
    {
        $segments = explode('.', strtolower($name));
        $extension = count($segments) > 1 ? (string) end($segments) : '';
        $interior = count($segments) > 2 ? array_slice($segments, 1, -1) : [];
        $real = $this->realMime($bytes);
        $fail = fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'extension' => $extension, 'mime' => $real];
        if (self::isExecutable($bytes)) {
            return $fail(self::R_EXECUTABLE);
        }
        if (array_intersect($interior, self::DANGEROUS_SEGMENTS) !== [] || in_array($extension, self::DANGEROUS_SEGMENTS, true) && count($segments) > 2) {
            return $fail(self::R_DOUBLE_EXTENSION);
        }
        if (!array_key_exists($extension, self::ALLOWED)) {
            return $fail(self::isMarkup($bytes, $real) ? self::R_MARKUP : (self::isArchive($bytes) ? self::R_ARCHIVE : self::R_EXTENSION));
        }
        $expected = self::ALLOWED[$extension];
        if (self::isArchive($bytes)) {
            return $fail(self::R_ARCHIVE);
        }
        if ($real !== $expected || !self::magicMatches($expected, $bytes)) {
            return $fail(self::isMarkup($bytes, $real) ? self::R_MARKUP : self::R_SIGNATURE);
        }
        return ['ok' => true, 'reason' => null, 'extension' => $extension, 'mime' => $expected];
    }

    /**
     * Decodes an allowed image with GD inside the dimension limits and RE-ENCODES it (drops EXIF/GPS/metadata and any
     * trailing polyglot payload). The returned bytes are the stored content (the checksum is taken from them).
     * @return array{ok: bool, reason: ?string, bytes: string}
     */
    public function normalizeImage(string $mime, string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return ['ok' => false, 'reason' => self::R_IMAGE_DECODE, 'bytes' => $bytes];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w * $h > $this->maxPixels || max($w, $h) > $this->maxSide || !$this->fitsInMemory($w, $h)) {
            return ['ok' => false, 'reason' => self::R_IMAGE_TOO_LARGE, 'bytes' => $bytes];
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return ['ok' => false, 'reason' => self::R_IMAGE_DECODE, 'bytes' => $bytes];
        }
        try {
            ob_start();
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 90),
                'image/png' => (imagesavealpha($image, true) || true) && imagepng($image, null, 6),
                'image/webp' => (imagesavealpha($image, true) || true) && imagewebp($image, null, 90),
                default => false,
            };
            $encoded = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }
        if (!$ok || $encoded === '' || $this->realMime($encoded) !== $mime) {
            return ['ok' => false, 'reason' => self::R_IMAGE_DECODE, 'bytes' => $bytes];
        }
        return ['ok' => true, 'reason' => null, 'bytes' => $encoded];
    }

    /**
     * Encrypted PDF or active content (/JavaScript, /JS, /Launch, /EmbeddedFile, /RichMedia, /XFA, /SubmitForm, /ImportData,
     * /GoToE, /AA, or an /OpenAction action dictionary),
     * searched in the raw body AND inside FlateDecode streams (object streams), with #xx name escapes decoded.
     */
    public function pdfReason(string $bytes): ?string
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            return self::R_SIGNATURE;
        }
        $bodies = [$bytes];
        if (preg_match_all('/stream\r?\n(.*?)\r?\n?endstream/s', $bytes, $streams) > 0) {
            foreach ($streams[1] as $stream) {
                $inflated = strlen($stream) <= 8388608 ? @zlib_decode($stream, 16777216) : false;
                if (is_string($inflated)) {
                    $bodies[] = $inflated;
                }
            }
        }
        foreach ($bodies as $body) {
            $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m): string => chr((int) hexdec($m[1])), $body) ?? $body;
            if (preg_match('#/Encrypt\b#', $names) === 1) {
                return self::R_PDF_ENCRYPTED;
            }
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData|GoToE|AA)(?![A-Za-z0-9])#', $names) === 1) {
                return self::R_PDF_ACTIVE;
            }
            // An /OpenAction that is an action dictionary (anything but a plain /GoTo) is active; a destination array
            // (`/OpenAction [3 0 R /Fit]`, the usual "open at page" of office exporters) is not.
            if (preg_match('#/OpenAction\s*<<(?:(?!>>).)*?/S\s*/(?!GoTo(?![A-Za-z0-9]))[A-Za-z]+#s', $names) === 1) {
                return self::R_PDF_ACTIVE;
            }
            if (stripos($names, '<?php') !== false) {
                return self::R_EXECUTABLE;
            }
        }
        return null;
    }

    public function realMime(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }

    public static function isExecutable(string $bytes): bool
    {
        $head = substr($bytes, 0, 8);
        foreach (["MZ", "\x7FELF", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", "\xCA\xFE\xBA\xBE", '#!'] as $magic) {
            if (str_starts_with($head, $magic)) {
                return true;
            }
        }
        return stripos(substr($bytes, 0, 1024), '<?php') !== false;
    }

    public static function isArchive(string $bytes): bool
    {
        foreach (["PK\x03\x04", "PK\x05\x06", "PK\x07\x08", "Rar!\x1A\x07", "7z\xBC\xAF\x27\x1C", "\x1F\x8B"] as $magic) {
            if (str_starts_with($bytes, $magic)) {
                return true;
            }
        }
        return strlen($bytes) > 262 && substr($bytes, 257, 5) === 'ustar';
    }

    private static function isMarkup(string $bytes, string $mime): bool
    {
        if (in_array($mime, ['text/html', 'image/svg+xml', 'text/xml', 'application/xml', 'application/xhtml+xml'], true)) {
            return true;
        }
        return preg_match('/^\s*(<\?xml|<!doctype html|<html|<svg|<script)/i', substr($bytes, 0, 512)) === 1;
    }

    private static function magicMatches(string $mime, string $bytes): bool
    {
        return match ($mime) {
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function fitsInMemory(int $w, int $h): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return true;
        }
        $unit = strtolower(substr($limit, -1));
        $bytes = (int) $limit * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        // GD truecolor ~5 bytes/pixel (+ the re-encoded output); keep the process inside its limit.
        return ($w * $h * 5) + memory_get_usage(true) + 16777216 < $bytes;
    }
}
