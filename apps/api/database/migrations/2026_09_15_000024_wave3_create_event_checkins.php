<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.3; approved catalogue. Early audit support: ADR 0012.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Qualified MySQL required');
        DB::statement(<<<'SQL'
CREATE TABLE `event_checkins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `registration_id` BIGINT UNSIGNED NOT NULL,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `credential_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `event_credential_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `checked_at` DATETIME(6) NOT NULL,
  `device_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_request_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_checkins_session_id_person_id` (`session_id`,`person_id`),
  UNIQUE KEY `uq_event_checkins_idempotency_request_id` (`idempotency_request_id`),
  KEY `ix_event_checkins_session_id_checked_at` (`session_id`,`checked_at`),
  KEY `ix_event_checkins_registration_id` (`registration_id`),
  KEY `ix_event_checkins_person_id` (`person_id`),
  KEY `ix_event_checkins_credential_id` (`credential_id`),
  KEY `ix_event_checkins_event_credential_id` (`event_credential_id`),
  KEY `ix_event_checkins_device_id` (`device_id`),
  KEY `ix_event_checkins_actor_id` (`actor_id`),
  CONSTRAINT `fk_event_checkins_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `event_registrations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_credential_id` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_event_credential_id` FOREIGN KEY (`event_credential_id`) REFERENCES `event_credentials` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_device_id` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_idempotency_request_id` FOREIGN KEY (`idempotency_request_id`) REFERENCES `idempotency_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `event_checkins`');
    }
};
