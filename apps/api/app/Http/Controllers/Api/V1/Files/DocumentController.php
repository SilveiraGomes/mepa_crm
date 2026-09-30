<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Files;

use App\Domain\Files\DocumentService;
use App\Domain\Files\FileContentService;
use App\Http\Controllers\Controller;
use App\Http\Files\FilesOutput;
use App\Http\Files\FilesServiceFactory;
use App\Http\Requests\Files\DocumentCreateRequest;
use App\Http\Requests\Files\DocumentLifecycleRequest;
use App\Http\Requests\Files\DocumentListRequest;
use App\Http\Requests\Files\DocumentOwnerRequest;
use App\Http\Requests\Files\DocumentUpdateRequest;
use App\Http\Requests\Files\DocumentVersionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// P0.8 Documents and immutable versions (ADR 0019 D08). Documents, versions and files are addressed by public_id only.
final class DocumentController extends Controller
{
    public function __construct(private FilesServiceFactory $factory)
    {
    }

    public function index(DocumentListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function show(Request $r, string $document): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->detail($u, $s, $document)); }
    public function update(DocumentUpdateRequest $r, string $document): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->update($u, $s, $document, $r->validated())); }
    public function archive(DocumentLifecycleRequest $r, string $document): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->archive($u, $s, $document, $r->validated(), true)); }
    public function restore(DocumentLifecycleRequest $r, string $document): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->archive($u, $s, $document, $r->validated(), false)); }
    public function owner(DocumentOwnerRequest $r, string $document): JsonResponse { [$u, $s] = $this->ids($r); return FilesOutput::item($this->svc()->transferOwner($u, $s, $document, $r->validated())); }

    public function store(DocumentCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $upload = $r->file('file');
        return FilesOutput::item($this->svc()->create($u, $s, $r->safe()->except('file'), $upload?->getRealPath() ?: null, $upload?->getClientOriginalName()), 201);
    }

    public function version(DocumentVersionRequest $r, string $document): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $upload = $r->file('file');
        return FilesOutput::item($this->svc()->addVersion($u, $s, $document, $r->safe()->except('file'), $upload?->getRealPath() ?: null, $upload?->getClientOriginalName()), 201);
    }

    public function content(Request $r, string $document, string $version): StreamedResponse
    {
        [$u, $s] = $this->ids($r);
        return FilesOutput::download($this->factory->make(FileContentService::class)->version($u, $s, $document, $version, FilesOutput::accessReason($r)));
    }

    private function svc(): DocumentService { return $this->factory->make(DocumentService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
