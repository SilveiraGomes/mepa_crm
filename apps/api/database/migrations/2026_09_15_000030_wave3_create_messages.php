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
CREATE TABLE `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `recipient_person_id` BIGINT UNSIGNED NOT NULL,
  `contact_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_request_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queued_at` DATETIME(6) NOT NULL,
  `payload_ciphertext` VARBINARY(2048) NULL DEFAULT NULL,
  `key_version` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messages_idempotency_request_id` (`idempotency_request_id`),
  KEY `ix_messages_recipient_person_id_queued_at` (`recipient_person_id`,`queued_at`),
  KEY `ix_messages_status_queued_at_id` (`status`,`queued_at`,`id`),
  KEY `ix_messages_campaign_id` (`campaign_id`),
  KEY `ix_messages_contact_id` (`contact_id`),
  KEY `ix_messages_template_id` (`template_id`),
  CONSTRAINT `fk_messages_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `communication_campaigns` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_recipient_person_id` FOREIGN KEY (`recipient_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `person_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_template_id` FOREIGN KEY (`template_id`) REFERENCES `communication_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_idempotency_request_id` FOREIGN KEY (`idempotency_request_id`) REFERENCES `idempotency_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `messages`');
    }
};
