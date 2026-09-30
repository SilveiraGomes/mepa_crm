<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;

// Collects the scope and clearance decisions an operation relied on, so FilesRuntime::write() re-verifies them with
// LOCKING reads as the last statements before commit (a grant revoked concurrently makes the operation roll back).
final class FilesGuard
{
    /** @var list<array{0: string, 1: int}> */
    private array $scopes = [];
    /** @var list<array{0: int, 1: string}> */
    private array $clearances = [];

    public function __construct(public FilesAuthority $authority, public TerritorialActor $actor)
    {
    }

    public function requires(string ...$permissions): void
    {
        $this->authority->requireAnywhere($this->actor, ...$permissions);
    }

    public function unit(string $permission, int $unit): void
    {
        $this->authority->requireUnit($this->actor, $permission, $unit, true);
        $this->scopes[] = [$permission, $unit];
    }

    /** Clearance over $unit must cover $classification (unknown classifications never pass). */
    public function cleared(int $unit, string $classification, string $failure = FilesReason::OUT_OF_SCOPE): void
    {
        if (!FileClassification::allows($this->authority->clearance($this->actor, $unit, true), $classification)) {
            throw new FilesError($failure, ['reason' => 'clearance']);
        }
        $this->clearances[] = [$unit, $classification];
    }

    /** @return list<array{0: string, 1: int}> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /** @return list<array{0: int, 1: string}> */
    public function clearances(): array
    {
        return $this->clearances;
    }
}
