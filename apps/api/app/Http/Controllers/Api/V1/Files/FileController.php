<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Files;

use App\Domain\Files\FileContentService;
use App\Domain\Files\FileService;
use App\Http\Controllers\Controller;
use App\Http\Files\FilesOutput;
use App\Http\Files\FilesServiceFactory;
use App\Http\Requests\Files\FileClassificationRequest;
use App\Http\Requests\Files\FileLifecycleRequest;
use App\Http\Requests\Files\FileListRequest;
use App\Http\Requests\Files\FileOwnerRequest;
use App\Http\Requests\Files\FileUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// P0.8 Files (ADR 0019). Every target is a public_id; the actor comes from the authenticated session. The content is
// served ONLY by content(): there is no public or signed URL.
final class FileController extends Controller
{
    public function __construct(private FilesServiceFactory $factory)
    {
    }

    public function context(Request $r): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->context($u, $s)); }
    public function index(FileListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function show(Request $r, string $file): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->detail($u, $s, $file)); }
    public function tombstone(FileLifecycleRequest $r, string $file): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->tombstone($u, $s, $file, $r->validated())); }
    public function restore(FileLifecycleRequest $r, string $file): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->restore($u, $s, $file, $r->validated())); }
    public function classification(FileClassificationRequest $r, string $file): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->reclassify($u, $s, $file, $r->validated())); }
    public function owner(FileOwnerRequest $r, string $file): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->transferOwner($u, $s, $file, $r->validated())); }

    public function store(FileUploadRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $upload = $r->file('file');
        return FilesOutput::item($this->svc()->upload($u, $s, $r->safe()->except('file'), (string) $upload->getRealPath(), $upload->getClientOriginalName()), 201);
    }

    /** HIGHLY_SENSITIVE content requires a reason in the X-Access-Reason header (kept out of URLs and access logs). */
    public function content(Request $r, string $file): StreamedResponse
    {
        [$u, $s] = $this->ids($r);
        return FilesOutput::download($this->factory->make(FileContentService::class)->file($u, $s, $file, FilesOutput::accessReason($r)));
    }

    private function svc(): FileService { return $this->factory->make(FileService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
