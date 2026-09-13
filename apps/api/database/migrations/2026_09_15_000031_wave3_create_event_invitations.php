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
CREATE TABLE `event_invitations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invitee_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `sent_at` DATETIME(6) NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_event_invitations_invitee_id` (`invitee_id`),
  KEY `ix_event_invitations_message_id` (`message_id`),
  CONSTRAINT `fk_event_invitations_invitee_id` FOREIGN KEY (`invitee_id`) REFERENCES `event_invitees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_invitations_message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `event_invitations`');
    }
};
