<?php

declare(strict_types=1);

namespace App\Http\Files;

use App\Domain\Files\FilesError;
use App\Domain\Files\FilesReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Last line of defence for every Documents/Files response (ADR 0019 D02/D04): no numeric key (`id`, `*_id` other than a
// public id), no disk, storage_key, checksum, key_version, path or staging name ever leaves the API, and no URL to the
// content exists besides the authorized endpoint.
final class FilesOutput
{
    public const FORBIDDEN = ['id', 'disk', 'storage_key', 'checksum', 'sha256', 'key_version', 'path', 'staging', 'url', 'signed_url', 'owner_unit_id', 'owner_department_id', 'file_id', 'document_id', 'created_by', 'supersedes_id'];

    public static function item(array $data, int $status = 200): JsonResponse
    {
        self::assertSafe($data);
        return response()->json(['data' => $data], $status, self::headers());
    }

    public static function page(Request $request, array $p): JsonResponse
    {
        self::assertSafe($p['items'] ?? []);
        $page = (int) ($p['page'] ?? 1);
        $per = (int) ($p['per_page'] ?? 50);
        $total = (int) ($p['total'] ?? 0);
        $last = max(1, (int) ceil($total / max(1, $per)));
        return response()->json(['data' => $p['items'] ?? [], 'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last], 'links' => [
            'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ]], 200, self::headers());
    }

    /**
     * D05 download response: stored allowlisted MIME, attachment disposition with an RFC 5987 sanitized filename,
     * nosniff, no-store/private, CSP sandbox, CORP same-origin, exact Content-Length, no Range.
     * @param array{file: object, stream: callable(): void} $served
     */
    public static function download(array $served): StreamedResponse
    {
        $file = $served['file'];
        $name = (string) $file->original_name;
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'ficheiro';
        $ascii = trim(str_replace(['"', '\\'], '_', $ascii)) ?: 'ficheiro';
        return new StreamedResponse($served['stream'], 200, [
            'Content-Type' => (string) $file->mime_type,
            'Content-Length' => (string) (int) $file->size_bytes,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Accept-Ranges' => 'none',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** X-Access-Reason is percent-encoded UTF-8 (header values are not UTF-8 safe); invalid encodings count as absent. */
    public static function accessReason(Request $request): ?string
    {
        $raw = $request->header('X-Access-Reason');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = rawurldecode($raw);
        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];
    }

    public static function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && (in_array($key, self::FORBIDDEN, true) || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')))) {
                throw new FilesError(FilesReason::INVARIANT_VIOLATION, ['reason' => 'internal_field_in_response']);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
