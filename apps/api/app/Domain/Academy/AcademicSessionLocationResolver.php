<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Connection;

// ADR-0015 Decision A precedence: an explicit session/event location prevails; otherwise the class
// default (classes.location_id). The default is never copied onto sessions. When an event has
// several locations the primary one wins, then the lowest id (deterministic).
final class AcademicSessionLocationResolver
{
    public const EVENT_SESSION = 'EVENT_SESSION';
    public const CLASS_DEFAULT = 'CLASS_DEFAULT';
    public const NONE = 'NONE';

    public function __construct(private Connection $db)
    {
    }

    public function effectiveAcademicSessionLocation(int $classSessionId): array
    {
        $session = $this->db->table('class_sessions')->where('id', $classSessionId)->first();
        if (!$session) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'class_sessions']);
        }
        if ($session->event_session_id !== null) {
            $eventId = $this->db->table('event_sessions')->where('id', $session->event_session_id)->value('event_id');
            $explicit = $eventId === null ? null : $this->db->table('event_locations')->where('event_id', $eventId)->orderByDesc('is_primary')->orderBy('id')->first();
            if ($explicit) {
                return ['location_id' => (int) $explicit->location_id, 'source' => self::EVENT_SESSION];
            }
        }
        $default = $this->db->table('classes')->where('id', $session->class_id)->value('location_id');
        return $default === null ? ['location_id' => null, 'source' => self::NONE] : ['location_id' => (int) $default, 'source' => self::CLASS_DEFAULT];
    }
}
