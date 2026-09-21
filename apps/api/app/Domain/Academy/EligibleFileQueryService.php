<?php

declare(strict_types=1);

namespace App\Domain\Academy;

final class EligibleFileQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function forCertificate(int $actor, int $session, int $enrollmentId, array $input): array
    {
        return $this->rt->read('certificate.file.select', $actor, $session, fn () => $this->rt->scope->forEnrollment($enrollmentId, null), fn (AcademyTarget $target) => $this->rt->resources->eligibleFiles($target, $input));
    }

    public function forTranscript(int $actor, int $session, int|string $personRef, int $curriculumId, array $input): array
    {
        return $this->rt->read('transcript.file.select', $actor, $session, fn () => $this->rt->scope->forTranscript($this->rt->people->find($personRef), $curriculumId, null), function (AcademyTarget $target) use ($personRef, $input): array {
            $this->rt->people->resolve($personRef);
            return $this->rt->resources->eligibleFiles($target, $input);
        });
    }
}
