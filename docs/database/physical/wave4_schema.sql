-- Schema-only snapshot from authorized isolated MySQL; no data.

CREATE TABLE `person_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_statuses_code` (`code`),
  CONSTRAINT `ck_person_statuses_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sex_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sex_types_code` (`code`),
  CONSTRAINT `ck_sex_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `civil_status_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_civil_status_types_code` (`code`),
  CONSTRAINT `ck_civil_status_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `people` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `full_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_precision` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `birth_year` smallint unsigned DEFAULT NULL,
  `sex_type_id` bigint unsigned DEFAULT NULL,
  `civil_status_type_id` bigint unsigned DEFAULT NULL,
  `status_id` bigint unsigned NOT NULL,
  `merged_into_id` bigint unsigned DEFAULT NULL,
  `archived_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_people_public_id` (`public_id`),
  KEY `ix_people_full_name_id` (`full_name`,`id`),
  KEY `ix_people_birth_date_id` (`birth_date`,`id`),
  KEY `ix_people_sex_type_id` (`sex_type_id`),
  KEY `ix_people_civil_status_type_id` (`civil_status_type_id`),
  KEY `ix_people_status_id` (`status_id`),
  KEY `ix_people_merged_into_id` (`merged_into_id`),
  CONSTRAINT `fk_people_civil_status_type_id` FOREIGN KEY (`civil_status_type_id`) REFERENCES `civil_status_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_merged_into_id` FOREIGN KEY (`merged_into_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_sex_type_id` FOREIGN KEY (`sex_type_id`) REFERENCES `sex_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_status_id` FOREIGN KEY (`status_id`) REFERENCES `person_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_people_birth` CHECK ((((`birth_precision` = _utf8mb4'EXACT') and (`birth_date` is not null) and (`birth_year` is null)) or ((`birth_precision` = _utf8mb4'YEAR_ONLY') and (`birth_date` is null) and (`birth_year` is not null)) or ((`birth_precision` = _utf8mb4'UNKNOWN') and (`birth_date` is null) and (`birth_year` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `identity_document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_identity_document_types_code` (`code`),
  CONSTRAINT `ck_identity_document_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_types_code` (`code`),
  CONSTRAINT `ck_contact_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `contact_type_id` bigint unsigned NOT NULL,
  `value_ciphertext` varbinary(2048) NOT NULL,
  `value_blind_index` binary(32) NOT NULL,
  `key_version` smallint unsigned NOT NULL,
  `is_primary` tinyint unsigned NOT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_contacts_person_id_contact_type_id` (`person_id`,`contact_type_id`),
  KEY `ix_person_contacts_value_blind_index` (`value_blind_index`),
  KEY `ix_person_contacts_contact_type_id` (`contact_type_id`),
  CONSTRAINT `fk_person_contacts_contact_type_id` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_contacts_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_contacts_is_primary` CHECK ((`is_primary` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `household_role_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_household_role_types_code` (`code`),
  CONSTRAINT `ck_household_role_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `relationship_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_relationship_types_code` (`code`),
  CONSTRAINT `ck_relationship_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `territorial_area_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_territorial_area_types_code` (`code`),
  CONSTRAINT `ck_territorial_area_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `territorial_areas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `area_type_id` bigint unsigned NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_territorial_areas_code` (`code`),
  KEY `ix_territorial_areas_parent_id_area_type_id` (`parent_id`,`area_type_id`),
  KEY `ix_territorial_areas_area_type_id` (`area_type_id`),
  CONSTRAINT `fk_territorial_areas_area_type_id` FOREIGN KEY (`area_type_id`) REFERENCES `territorial_area_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_territorial_areas_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `territorial_areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `country_code` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `province_id` bigint unsigned DEFAULT NULL,
  `municipality_id` bigint unsigned DEFAULT NULL,
  `line1_ciphertext` varbinary(2048) NOT NULL,
  `locality` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `key_version` smallint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_addresses_province_id` (`province_id`),
  KEY `ix_addresses_municipality_id` (`municipality_id`),
  CONSTRAINT `fk_addresses_municipality_id` FOREIGN KEY (`municipality_id`) REFERENCES `territorial_areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_addresses_province_id` FOREIGN KEY (`province_id`) REFERENCES `territorial_areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `households` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_households_code` (`code`),
  UNIQUE KEY `uq_households_public_id` (`public_id`),
  KEY `ix_households_address_id` (`address_id`),
  CONSTRAINT `fk_households_address_id` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizational_unit_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizational_unit_types_code` (`code`),
  CONSTRAINT `ck_organizational_unit_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_parent_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `child_type_id` bigint unsigned NOT NULL,
  `parent_type_id` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_parent_rules_child_type_id_parent_type_id` (`child_type_id`,`parent_type_id`),
  KEY `ix_unit_parent_rules_parent_type_id` (`parent_type_id`),
  CONSTRAINT `fk_unit_parent_rules_child_type_id` FOREIGN KEY (`child_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_parent_rules_parent_type_id` FOREIGN KEY (`parent_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizational_units` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `unit_type_id` bigint unsigned NOT NULL,
  `municipality_id` bigint unsigned DEFAULT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `opened_on` date DEFAULT NULL,
  `closed_on` date DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizational_units_public_id` (`public_id`),
  UNIQUE KEY `uq_organizational_units_code` (`code`),
  KEY `ix_organizational_units_parent_id_unit_type_id_status` (`parent_id`,`unit_type_id`,`status`),
  KEY `ix_organizational_units_municipality_id_unit_type_id` (`municipality_id`,`unit_type_id`),
  KEY `ix_organizational_units_unit_type_id` (`unit_type_id`),
  CONSTRAINT `fk_organizational_units_municipality_id` FOREIGN KEY (`municipality_id`) REFERENCES `territorial_areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_organizational_units_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_organizational_units_unit_type_id` FOREIGN KEY (`unit_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_organizational_units_status` CHECK ((`status` in (_utf8mb4'DRAFT',_utf8mb4'ACTIVE',_utf8mb4'CLOSED')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizational_structure_lock` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizational_structure_lock_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_unit_id` bigint unsigned NOT NULL,
  `owner_department_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `classification` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum` binary(32) NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted_at` datetime(6) DEFAULT NULL,
  `purged_at` datetime(6) DEFAULT NULL,
  `key_version` smallint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_files_public_id` (`public_id`),
  UNIQUE KEY `uq_files_disk_storage_key` (`disk`,`storage_key`),
  KEY `ix_files_owner_unit_id_classification_status` (`owner_unit_id`,`classification`,`status`),
  KEY `ix_files_owner_department_id` (`owner_department_id`),
  KEY `ix_files_created_by` (`created_by`),
  CONSTRAINT `fk_files_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_files_owner_department_id` FOREIGN KEY (`owner_department_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_files_owner_unit_id` FOREIGN KEY (`owner_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_files_status` CHECK ((`status` in (_utf8mb4'QUARANTINED',_utf8mb4'AVAILABLE',_utf8mb4'TOMBSTONE',_utf8mb4'PURGED')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `issuer_country` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `number_ciphertext` varbinary(2048) NOT NULL,
  `number_blind_index` binary(32) NOT NULL,
  `key_version` smallint unsigned NOT NULL,
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `file_id` bigint unsigned DEFAULT NULL,
  `verification_status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_docs_type_country_blind` (`document_type_id`,`issuer_country`,`number_blind_index`),
  KEY `ix_person_documents_person_id` (`person_id`),
  KEY `ix_person_documents_file_id` (`file_id`),
  CONSTRAINT `fk_person_documents_document_type_id` FOREIGN KEY (`document_type_id`) REFERENCES `identity_document_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_documents_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_documents_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `purpose` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_files_person_id_file_id_purpose` (`person_id`,`file_id`,`purpose`),
  KEY `ix_person_files_file_id` (`file_id`),
  CONSTRAINT `fk_person_files_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_files_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legal_document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legal_document_types_code` (`code`),
  CONSTRAINT `ck_legal_document_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legal_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `owner_unit_id` bigint unsigned NOT NULL,
  `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legal_documents_public_id` (`public_id`),
  KEY `ix_legal_documents_owner_unit_id_reference` (`owner_unit_id`,`reference`),
  KEY `ix_legal_documents_document_type_id` (`document_type_id`),
  CONSTRAINT `fk_legal_documents_document_type_id` FOREIGN KEY (`document_type_id`) REFERENCES `legal_document_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_legal_documents_owner_unit_id` FOREIGN KEY (`owner_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `address_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_addresses_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_person_addresses_address_id` (`address_id`),
  KEY `ix_person_addresses_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_person_addresses_address_id` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_addresses_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_addresses_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_addresses_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `household_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `household_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `role_type_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_household_members_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_household_members_household_id_starts_at` (`household_id`,`starts_at`),
  KEY `ix_household_members_role_type_id` (`role_type_id`),
  KEY `ix_household_members_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_household_members_household_id` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_household_members_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_household_members_role_type_id` FOREIGN KEY (`role_type_id`) REFERENCES `household_role_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_household_members_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_household_members_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_relationships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_person_id` bigint unsigned NOT NULL,
  `related_person_id` bigint unsigned NOT NULL,
  `relationship_type_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_relationships_subject_person_id_relationship_type_id` (`subject_person_id`,`relationship_type_id`),
  KEY `ix_person_relationships_related_person_id_relationship_type_id` (`related_person_id`,`relationship_type_id`),
  KEY `ix_person_relationships_relationship_type_id` (`relationship_type_id`),
  KEY `ix_person_relationships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_person_relationships_related_person_id` FOREIGN KEY (`related_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_relationship_type_id` FOREIGN KEY (`relationship_type_id`) REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_subject_person_id` FOREIGN KEY (`subject_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_relationships_not_reflexive` CHECK ((`subject_person_id` <> `related_person_id`)),
  CONSTRAINT `ck_person_relationships_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_parent_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` bigint unsigned NOT NULL,
  `parent_unit_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_unit_parent_periods_unit_id_starts_at` (`unit_id`,`starts_at`),
  KEY `ix_unit_parent_periods_parent_unit_id` (`parent_unit_id`),
  KEY `ix_unit_parent_periods_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_unit_parent_periods_parent_unit_id` FOREIGN KEY (`parent_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_parent_periods_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_parent_periods_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_unit_parent_periods_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `issued_on` date DEFAULT NULL,
  `supersedes_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_versions_document_id_version` (`document_id`,`version`),
  UNIQUE KEY `uq_document_versions_public_id` (`public_id`),
  KEY `ix_document_versions_file_id` (`file_id`),
  KEY `ix_document_versions_supersedes_id` (`supersedes_id`),
  CONSTRAINT `fk_document_versions_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_document_versions_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_document_versions_supersedes_id` FOREIGN KEY (`supersedes_id`) REFERENCES `document_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_document_versions_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `physical_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `address_id` bigint unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `geocode_accuracy` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_visibility` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_physical_locations_public_id` (`public_id`),
  KEY `ix_physical_locations_latitude_longitude_id` (`latitude`,`longitude`,`id`),
  KEY `ix_physical_locations_address_id` (`address_id`),
  CONSTRAINT `fk_physical_locations_address_id` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_physical_locations_coordinates` CHECK ((((`latitude` is null) and (`longitude` is null)) or ((`latitude` is not null) and (`longitude` is not null) and (`latitude` between -(90) and 90) and (`longitude` between -(180) and 180)))),
  CONSTRAINT `ck_physical_locations_visibility` CHECK ((`public_visibility` in (_utf8mb4'PRIVATE',_utf8mb4'APPROVED_PUBLIC')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `owner_person_id` bigint unsigned DEFAULT NULL,
  `owner_name_external` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ownership_status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_properties_code` (`code`),
  UNIQUE KEY `uq_properties_public_id` (`public_id`),
  KEY `ix_properties_location_id` (`location_id`),
  KEY `ix_properties_owner_person_id` (`owner_person_id`),
  CONSTRAINT `fk_properties_location_id` FOREIGN KEY (`location_id`) REFERENCES `physical_locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_properties_owner_person_id` FOREIGN KEY (`owner_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `occupation_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_occupation_types_code` (`code`),
  CONSTRAINT `ck_occupation_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_location_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `occupation_type_id` bigint unsigned NOT NULL,
  `is_primary` tinyint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_unit_location_links_unit_id_starts_at` (`unit_id`,`starts_at`),
  KEY `ix_unit_location_links_location_id_starts_at` (`location_id`,`starts_at`),
  KEY `ix_unit_location_links_property_id` (`property_id`),
  KEY `ix_unit_location_links_occupation_type_id` (`occupation_type_id`),
  KEY `ix_unit_location_links_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_unit_location_links_location_id` FOREIGN KEY (`location_id`) REFERENCES `physical_locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_location_links_occupation_type_id` FOREIGN KEY (`occupation_type_id`) REFERENCES `occupation_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_location_links_property_id` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_location_links_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_location_links_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_unit_location_links_is_primary` CHECK ((`is_primary` in (0,1))),
  CONSTRAINT `ck_unit_location_links_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `education_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_education_types_code` (`code`),
  CONSTRAINT `ck_education_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employment_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employment_types_code` (`code`),
  CONSTRAINT `ck_employment_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_qualifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `education_type_id` bigint unsigned NOT NULL,
  `institution_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_year` smallint unsigned DEFAULT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_qualifications_person_id` (`person_id`),
  KEY `ix_person_qualifications_education_type_id` (`education_type_id`),
  KEY `ix_person_qualifications_document_id` (`document_id`),
  CONSTRAINT `fk_person_qualifications_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_qualifications_education_type_id` FOREIGN KEY (`education_type_id`) REFERENCES `education_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_qualifications_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_employment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `employment_type_id` bigint unsigned NOT NULL,
  `profession` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_employment_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_person_employment_employment_type_id` (`employment_type_id`),
  KEY `ix_person_employment_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_person_employment_employment_type_id` FOREIGN KEY (`employment_type_id`) REFERENCES `employment_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_employment_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_employment_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_employment_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_merges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_person_id` bigint unsigned NOT NULL,
  `target_person_id` bigint unsigned NOT NULL,
  `approved_by` bigint unsigned NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_metadata` json NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_person_merges_source_person_id` (`source_person_id`),
  KEY `ix_person_merges_target_person_id` (`target_person_id`),
  KEY `ix_person_merges_approved_by` (`approved_by`),
  CONSTRAINT `fk_person_merges_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_merges_source_person_id` FOREIGN KEY (`source_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_merges_target_person_id` FOREIGN KEY (`target_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_merges_not_self` CHECK ((`source_person_id` <> `target_person_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_membership_statuses_code` (`code`),
  CONSTRAINT `ck_membership_statuses_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `memberships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `status_id` bigint unsigned NOT NULL,
  `admitted_on` date DEFAULT NULL,
  `date_precision` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime(6) DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `origin` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memberships_person_id` (`person_id`),
  UNIQUE KEY `uq_memberships_public_id` (`public_id`),
  KEY `ix_memberships_status_id` (`status_id`),
  KEY `ix_memberships_approved_by` (`approved_by`),
  KEY `ix_memberships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_memberships_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_status_id` FOREIGN KEY (`status_id`) REFERENCES `membership_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `membership_id` bigint unsigned NOT NULL,
  `congregation_id` bigint unsigned NOT NULL,
  `status_id` bigint unsigned NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_membership_periods_membership_id_starts_at` (`membership_id`,`starts_at`),
  KEY `ix_membership_periods_congregation_id_status_id_starts_at` (`congregation_id`,`status_id`,`starts_at`),
  KEY `ix_membership_periods_status_id` (`status_id`),
  KEY `ix_membership_periods_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_membership_periods_congregation_id` FOREIGN KEY (`congregation_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_status_id` FOREIGN KEY (`status_id`) REFERENCES `membership_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_membership_periods_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_number_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `last_value` int unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_number_sequences_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_numbers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `membership_id` bigint unsigned NOT NULL,
  `number` char(14) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence_value` int unsigned NOT NULL,
  `issued_year` smallint unsigned NOT NULL,
  `issued_month` smallint unsigned NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `origin` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_numbers_membership_id` (`membership_id`),
  UNIQUE KEY `uq_member_numbers_number` (`number`),
  UNIQUE KEY `uq_member_numbers_sequence_value` (`sequence_value`),
  CONSTRAINT `fk_member_numbers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_member_numbers_issued_month` CHECK ((`issued_month` between 1 and 12)),
  CONSTRAINT `ck_member_numbers_origin` CHECK ((`origin` in (_utf8mb4'APPROVED_ADMISSION',_utf8mb4'APPROVED_LEGACY_MAPPING'))),
  CONSTRAINT `ck_member_numbers_sequence_value` CHECK ((`sequence_value` between 1 and 999999))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `milestone_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_milestone_types_code` (`code`),
  CONSTRAINT `ck_milestone_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ecclesiastical_milestones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `milestone_type_id` bigint unsigned NOT NULL,
  `occurred_on` date DEFAULT NULL,
  `date_precision` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_ecclesiastical_milestones_person_id` (`person_id`),
  KEY `ix_ecclesiastical_milestones_milestone_type_id` (`milestone_type_id`),
  KEY `ix_ecclesiastical_milestones_unit_id` (`unit_id`),
  KEY `ix_ecclesiastical_milestones_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_ecclesiastical_milestones_milestone_type_id` FOREIGN KEY (`milestone_type_id`) REFERENCES `milestone_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ecclesiastical_milestones_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ecclesiastical_milestones_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ecclesiastical_milestones_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ministerial_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ministerial_classes_code` (`code`),
  CONSTRAINT `ck_ministerial_classes_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `positions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_positions_code` (`code`),
  CONSTRAINT `ck_positions_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `functions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_functions_code` (`code`),
  CONSTRAINT `ck_functions_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ministerial_class_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_ministerial_class_periods_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_ministerial_class_periods_class_id` (`class_id`),
  KEY `ix_ministerial_class_periods_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_ministerial_class_periods_class_id` FOREIGN KEY (`class_id`) REFERENCES `ministerial_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ministerial_class_periods_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ministerial_class_periods_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_ministerial_class_periods_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizational_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `position_id` bigint unsigned NOT NULL,
  `slot` int unsigned NOT NULL,
  `occupancy_status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizational_posts_unit_id_position_id_slot` (`unit_id`,`position_id`,`slot`),
  UNIQUE KEY `uq_organizational_posts_public_id` (`public_id`),
  KEY `ix_organizational_posts_position_id` (`position_id`),
  CONSTRAINT `fk_organizational_posts_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_organizational_posts_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_organizational_posts_occupancy_status` CHECK ((`occupancy_status` in (_utf8mb4'VACANT',_utf8mb4'FILLED',_utf8mb4'INTERIM',_utf8mb4'INACTIVE'))),
  CONSTRAINT `ck_organizational_posts_slot` CHECK ((`slot` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ministerial_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `post_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `appointment_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ministerial_assignments_public_id` (`public_id`),
  KEY `ix_ministerial_assignments_post_id_starts_at` (`post_id`,`starts_at`),
  KEY `ix_ministerial_assignments_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_ministerial_assignments_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_ministerial_assignments_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ministerial_assignments_post_id` FOREIGN KEY (`post_id`) REFERENCES `organizational_posts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ministerial_assignments_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_ministerial_assignments_appointment_kind` CHECK ((`appointment_kind` in (_utf8mb4'SUBSTANTIVE',_utf8mb4'INTERIM'))),
  CONSTRAINT `ck_ministerial_assignments_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `function_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `function_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_function_assignments_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_function_assignments_function_id` (`function_id`),
  KEY `ix_function_assignments_unit_id` (`unit_id`),
  KEY `ix_function_assignments_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_function_assignments_function_id` FOREIGN KEY (`function_id`) REFERENCES `functions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_function_assignments_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_function_assignments_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_function_assignments_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_function_assignments_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_categories_code` (`code`),
  CONSTRAINT `ck_department_categories_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`code`),
  KEY `ix_departments_category_id` (`category_id`),
  CONSTRAINT `fk_departments_category_id` FOREIGN KEY (`category_id`) REFERENCES `department_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_applicability` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `unit_type_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_applicability_department_id_unit_type_id` (`department_id`,`unit_type_id`),
  KEY `ix_department_applicability_unit_type_id` (`unit_type_id`),
  CONSTRAINT `fk_department_applicability_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_applicability_unit_type_id` FOREIGN KEY (`unit_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `department_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_instances_department_id_unit_id` (`department_id`,`unit_id`),
  UNIQUE KEY `uq_department_instances_public_id` (`public_id`),
  KEY `ix_department_instances_unit_id_status` (`unit_id`,`status`),
  CONSTRAINT `fk_department_instances_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_instances_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_department_instances_status` CHECK ((`status` in (_utf8mb4'NON_CONSTITUTED',_utf8mb4'ACTIVE',_utf8mb4'INACTIVE')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `instance_id` bigint unsigned NOT NULL,
  `position_id` bigint unsigned NOT NULL,
  `slot` int unsigned NOT NULL,
  `occupancy_status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_posts_instance_id_position_id_slot` (`instance_id`,`position_id`,`slot`),
  UNIQUE KEY `uq_department_posts_public_id` (`public_id`),
  KEY `ix_department_posts_position_id` (`position_id`),
  CONSTRAINT `fk_department_posts_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_posts_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_department_posts_occupancy_status` CHECK ((`occupancy_status` in (_utf8mb4'VACANT',_utf8mb4'FILLED',_utf8mb4'INTERIM',_utf8mb4'INACTIVE'))),
  CONSTRAINT `ck_department_posts_slot` CHECK ((`slot` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_appointments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `post_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `appointment_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_appointments_public_id` (`public_id`),
  KEY `ix_department_appointments_post_id_starts_at` (`post_id`,`starts_at`),
  KEY `ix_department_appointments_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_department_appointments_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_department_appointments_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_appointments_post_id` FOREIGN KEY (`post_id`) REFERENCES `department_posts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_appointments_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_department_appointments_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_memberships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_department_memberships_instance_id_starts_at` (`instance_id`,`starts_at`),
  KEY `ix_department_memberships_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_department_memberships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_department_memberships_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_memberships_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_memberships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_department_memberships_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `registered_by` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_public_id` (`public_id`),
  KEY `ix_devices_registered_by` (`registered_by`),
  KEY `ix_devices_unit_id` (`unit_id`),
  CONSTRAINT `fk_devices_registered_by` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_devices_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` binary(32) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `ip_hash` binary(32) DEFAULT NULL,
  `device_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_sessions_token_hash` (`token_hash`),
  KEY `ix_auth_sessions_user_id_expires_at` (`user_id`,`expires_at`),
  KEY `ix_auth_sessions_device_id` (`device_id`),
  CONSTRAINT `fk_auth_sessions_device_id` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_auth_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`),
  CONSTRAINT `ck_roles_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `action` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `maximum_classification` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permissions_role_id_permission_id` (`role_id`,`permission_id`),
  KEY `ix_role_permissions_permission_id` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_role_permissions_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scopes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` bigint unsigned NOT NULL,
  `department_instance_id` bigint unsigned DEFAULT NULL,
  `include_descendants` tinyint unsigned NOT NULL,
  `scope_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_scopes_unit_id_department_instance_id` (`unit_id`,`department_instance_id`),
  KEY `ix_scopes_department_instance_id` (`department_instance_id`),
  CONSTRAINT `fk_scopes_department_instance_id` FOREIGN KEY (`department_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_scopes_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_scopes_include_descendants` CHECK ((`include_descendants` in (0,1))),
  CONSTRAINT `ck_scopes_scope_kind` CHECK ((`scope_kind` in (_utf8mb4'UNIT',_utf8mb4'UNIT_DEPARTMENT')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_role_scopes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `scope_id` bigint unsigned NOT NULL,
  `granted_by` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_user_role_scopes_user_id_status_starts_at` (`user_id`,`status`,`starts_at`),
  KEY `ix_user_role_scopes_role_id` (`role_id`),
  KEY `ix_user_role_scopes_scope_id` (`scope_id`),
  KEY `ix_user_role_scopes_granted_by` (`granted_by`),
  KEY `ix_user_role_scopes_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_user_role_scopes_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_role_scopes_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_role_scopes_scope_id` FOREIGN KEY (`scope_id`) REFERENCES `scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_role_scopes_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_role_scopes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_user_role_scopes_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `version` int unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workflows_code_version` (`code`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflow_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workflow_instances_public_id` (`public_id`),
  KEY `ix_workflow_instances_workflow_id` (`workflow_id`),
  KEY `ix_workflow_instances_unit_id` (`unit_id`),
  KEY `ix_workflow_instances_requested_by` (`requested_by`),
  CONSTRAINT `fk_workflow_instances_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_workflow_instances_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_workflow_instances_workflow_id` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `membership_id` bigint unsigned NOT NULL,
  `origin_unit_id` bigint unsigned NOT NULL,
  `destination_unit_id` bigint unsigned NOT NULL,
  `requested_at` datetime(6) NOT NULL,
  `effective_at` datetime(6) DEFAULT NULL,
  `closed_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `workflow_instance_id` bigint unsigned NOT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `open_flag` tinyint unsigned GENERATED ALWAYS AS (if((`closed_at` is null),1,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfers_public_id` (`public_id`),
  UNIQUE KEY `uq_transfers_membership_open` (`membership_id`,`open_flag`),
  KEY `ix_transfers_membership_id_status` (`membership_id`,`status`),
  KEY `ix_transfers_destination_unit_id_status` (`destination_unit_id`,`status`),
  KEY `ix_transfers_origin_unit_id` (`origin_unit_id`),
  KEY `ix_transfers_workflow_instance_id` (`workflow_instance_id`),
  KEY `ix_transfers_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_transfers_destination_unit_id` FOREIGN KEY (`destination_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_origin_unit_id` FOREIGN KEY (`origin_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_workflow_instance_id` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_transfers_origin_destination` CHECK ((`origin_unit_id` <> `destination_unit_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `idempotency_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned NOT NULL,
  `operation` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` binary(32) NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_public_id` char(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency_requests_actor_id_operation_client_key` (`actor_id`,`operation`,`client_key`),
  CONSTRAINT `fk_idempotency_requests_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_system` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `source_hash` binary(32) NOT NULL,
  `mapping_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_batches_public_id` (`public_id`),
  KEY `ix_import_batches_source_hash_mapping_version` (`source_hash`,`mapping_version`),
  KEY `ix_import_batches_file_id` (`file_id`),
  KEY `ix_import_batches_approved_by` (`approved_by`),
  CONSTRAINT `fk_import_batches_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_import_batches_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `row_number` int unsigned NOT NULL,
  `raw_ciphertext` varbinary(2048) NOT NULL,
  `key_version` smallint unsigned NOT NULL,
  `row_hash` binary(32) NOT NULL,
  `person_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_records_batch_id_row_number` (`batch_id`,`row_number`),
  KEY `ix_import_records_person_id` (`person_id`),
  CONSTRAINT `fk_import_records_batch_id` FOREIGN KEY (`batch_id`) REFERENCES `import_batches` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_import_records_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legacy_member_numbers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `membership_id` bigint unsigned NOT NULL,
  `source_system` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_number` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_number` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `import_record_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_legacy_member_numbers_source_system_normalized_number` (`source_system`,`normalized_number`),
  KEY `ix_legacy_member_numbers_membership_id` (`membership_id`),
  KEY `ix_legacy_member_numbers_import_record_id` (`import_record_id`),
  CONSTRAINT `fk_legacy_member_numbers_import_record_id` FOREIGN KEY (`import_record_id`) REFERENCES `import_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_legacy_member_numbers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_issues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `record_id` bigint unsigned NOT NULL,
  `field_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolution_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_import_issues_record_id` (`record_id`),
  KEY `ix_import_issues_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_import_issues_record_id` FOREIGN KEY (`record_id`) REFERENCES `import_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_import_issues_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_identity_maps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_system` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_identifier` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_identity_maps_source_system_source_identifier` (`source_system`,`source_identifier`),
  KEY `ix_import_identity_maps_person_id` (`person_id`),
  KEY `ix_import_identity_maps_record_id` (`record_id`),
  CONSTRAINT `fk_import_identity_maps_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_import_identity_maps_record_id` FOREIGN KEY (`record_id`) REFERENCES `import_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned DEFAULT NULL,
  `account_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `login` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mfa_required` tinyint unsigned NOT NULL,
  `archived_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_public_id` (`public_id`),
  UNIQUE KEY `uq_users_login` (`login`),
  UNIQUE KEY `uq_users_person_id` (`person_id`),
  CONSTRAINT `fk_users_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_users_account_kind` CHECK ((`account_kind` in (_utf8mb4'HUMAN',_utf8mb4'SERVICE'))),
  CONSTRAINT `ck_users_mfa_required` CHECK ((`mfa_required` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credential_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `requires_member_number` tinyint unsigned NOT NULL,
  `default_validity_days` int unsigned DEFAULT NULL,
  `requires_formal_approval` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential_types_code` (`code`),
  CONSTRAINT `ck_credential_types_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `ck_credential_types_requires_formal_approval` CHECK ((`requires_formal_approval` in (0,1))),
  CONSTRAINT `ck_credential_types_requires_member_number` CHECK ((`requires_member_number` in (0,1))),
  CONSTRAINT `ck_credential_types_validity` CHECK (((`default_validity_days` is null) or (`default_validity_days` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credential_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential_classes_code` (`code`),
  CONSTRAINT `ck_credential_classes_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credential_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credential_type_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `file_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential_templates_credential_type_id_version` (`credential_type_id`,`version`),
  KEY `ix_credential_templates_file_id` (`file_id`),
  CONSTRAINT `fk_credential_templates_credential_type_id` FOREIGN KEY (`credential_type_id`) REFERENCES `credential_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credential_templates_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_credential_templates_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credential_class_styles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `color` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text_color` char(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential_class_styles_template_id_class_id` (`template_id`,`class_id`),
  KEY `ix_credential_class_styles_class_id` (`class_id`),
  CONSTRAINT `fk_credential_class_styles_class_id` FOREIGN KEY (`class_id`) REFERENCES `credential_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credential_class_styles_template_id` FOREIGN KEY (`template_id`) REFERENCES `credential_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credentials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `credential_type_id` bigint unsigned NOT NULL,
  `member_number_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `token_hash` binary(32) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `revoked_by` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `render_file_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credentials_token_hash` (`token_hash`),
  UNIQUE KEY `uq_credentials_person_id_credential_type_id_version` (`person_id`,`credential_type_id`,`version`),
  UNIQUE KEY `uq_credentials_public_id` (`public_id`),
  KEY `ix_credentials_person_id_status` (`person_id`,`status`),
  KEY `ix_credentials_credential_type_id` (`credential_type_id`),
  KEY `ix_credentials_member_number_id` (`member_number_id`),
  KEY `ix_credentials_template_id` (`template_id`),
  KEY `ix_credentials_revoked_by` (`revoked_by`),
  KEY `ix_credentials_render_file_id` (`render_file_id`),
  CONSTRAINT `fk_credentials_credential_type_id` FOREIGN KEY (`credential_type_id`) REFERENCES `credential_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_member_number_id` FOREIGN KEY (`member_number_id`) REFERENCES `member_numbers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_render_file_id` FOREIGN KEY (`render_file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_template_id` FOREIGN KEY (`template_id`) REFERENCES `credential_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_credentials_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_body_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_governance_body_types_code` (`code`),
  CONSTRAINT `ck_governance_body_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_bodies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `body_type_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_governance_bodies_code` (`code`),
  UNIQUE KEY `uq_governance_bodies_public_id` (`public_id`),
  KEY `ix_governance_bodies_body_type_id` (`body_type_id`),
  KEY `ix_governance_bodies_unit_id` (`unit_id`),
  CONSTRAINT `fk_governance_bodies_body_type_id` FOREIGN KEY (`body_type_id`) REFERENCES `governance_body_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_bodies_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_body_memberships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `body_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `position_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_governance_body_memberships_body_id_starts_at` (`body_id`,`starts_at`),
  KEY `ix_governance_body_memberships_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_governance_body_memberships_position_id` (`position_id`),
  KEY `ix_governance_body_memberships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_governance_body_memberships_body_id` FOREIGN KEY (`body_id`) REFERENCES `governance_bodies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_body_memberships_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_body_memberships_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_body_memberships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_governance_body_memberships_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_types_code` (`code`),
  CONSTRAINT `ck_event_types_is_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type_id` bigint unsigned NOT NULL,
  `owner_unit_id` bigint unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `eligibility_policy_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_events_public_id` (`public_id`),
  KEY `ix_events_owner_unit_id_starts_at` (`owner_unit_id`,`starts_at`),
  KEY `ix_events_event_type_id` (`event_type_id`),
  CONSTRAINT `fk_events_event_type_id` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_events_owner_unit_id` FOREIGN KEY (`owner_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_events_period` CHECK ((`ends_at` > `starts_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_activities_instance_id_event_id` (`instance_id`,`event_id`),
  KEY `ix_department_activities_event_id` (`event_id`),
  CONSTRAINT `fk_department_activities_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_activities_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) NOT NULL,
  `checkin_policy` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_sessions_public_id` (`public_id`),
  KEY `ix_event_sessions_event_id_starts_at` (`event_id`,`starts_at`),
  CONSTRAINT `fk_event_sessions_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_sessions_period` CHECK ((`ends_at` > `starts_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `body_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `session_reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_governance_sessions_body_id_event_id` (`body_id`,`event_id`),
  KEY `ix_governance_sessions_event_id` (`event_id`),
  CONSTRAINT `fk_governance_sessions_body_id` FOREIGN KEY (`body_id`) REFERENCES `governance_bodies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_sessions_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_resolutions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `governance_session_id` bigint unsigned NOT NULL,
  `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_governance_resolutions_governance_session_id_reference` (`governance_session_id`,`reference`),
  UNIQUE KEY `uq_governance_resolutions_public_id` (`public_id`),
  KEY `ix_governance_resolutions_document_id` (`document_id`),
  CONSTRAINT `fk_governance_resolutions_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_governance_resolutions_governance_session_id` FOREIGN KEY (`governance_session_id`) REFERENCES `governance_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_organizers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `function_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_organizers_event_id_person_id` (`event_id`,`person_id`),
  KEY `ix_event_organizers_person_id` (`person_id`),
  KEY `ix_event_organizers_function_id` (`function_id`),
  CONSTRAINT `fk_event_organizers_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_organizers_function_id` FOREIGN KEY (`function_id`) REFERENCES `functions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_organizers_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `is_primary` tinyint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_locations_event_id_location_id` (`event_id`,`location_id`),
  KEY `ix_event_locations_location_id` (`location_id`),
  CONSTRAINT `fk_event_locations_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_locations_location_id` FOREIGN KEY (`location_id`) REFERENCES `physical_locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_locations_is_primary` CHECK ((`is_primary` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_documents_event_id_document_id` (`event_id`,`document_id`),
  KEY `ix_event_documents_document_id` (`document_id`),
  CONSTRAINT `fk_event_documents_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_documents_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_invitation_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `frozen_at` datetime(6) DEFAULT NULL,
  `selection_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_invitation_lists_event_id_version` (`event_id`,`version`),
  CONSTRAINT `fk_event_invitation_lists_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_invitation_lists_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invitation_criteria` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `list_id` bigint unsigned NOT NULL,
  `criterion_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position_id` bigint unsigned DEFAULT NULL,
  `class_id` bigint unsigned DEFAULT NULL,
  `body_id` bigint unsigned DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `include_descendants` tinyint unsigned NOT NULL,
  `operator` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_invitation_criteria_list_id` (`list_id`),
  KEY `ix_invitation_criteria_position_id` (`position_id`),
  KEY `ix_invitation_criteria_class_id` (`class_id`),
  KEY `ix_invitation_criteria_body_id` (`body_id`),
  KEY `ix_invitation_criteria_unit_id` (`unit_id`),
  KEY `ix_invitation_criteria_department_id` (`department_id`),
  CONSTRAINT `fk_invitation_criteria_body_id` FOREIGN KEY (`body_id`) REFERENCES `governance_bodies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_class_id` FOREIGN KEY (`class_id`) REFERENCES `ministerial_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_department_id` FOREIGN KEY (`department_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_list_id` FOREIGN KEY (`list_id`) REFERENCES `event_invitation_lists` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_invitation_criteria_include_descendants` CHECK ((`include_descendants` in (0,1))),
  CONSTRAINT `ck_invitation_criteria_target` CHECK ((((`position_id` is not null) and (`class_id` is null) and (`body_id` is null) and (`unit_id` is null) and (`department_id` is null)) or ((`position_id` is null) and (`class_id` is not null) and (`body_id` is null) and (`unit_id` is null) and (`department_id` is null)) or ((`position_id` is null) and (`class_id` is null) and (`body_id` is not null) and (`unit_id` is null) and (`department_id` is null)) or ((`position_id` is null) and (`class_id` is null) and (`body_id` is null) and (`unit_id` is not null) and (`department_id` is null)) or ((`position_id` is null) and (`class_id` is null) and (`body_id` is null) and (`unit_id` is null) and (`department_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_invitees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `list_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `selection_origin` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `eligibility_evidence` json NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_invitees_list_id_person_id` (`list_id`,`person_id`),
  KEY `ix_event_invitees_person_id` (`person_id`),
  CONSTRAINT `fk_event_invitees_list_id` FOREIGN KEY (`list_id`) REFERENCES `event_invitation_lists` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_invitees_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_registrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `invitee_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_registrations_event_id_person_id` (`event_id`,`person_id`),
  UNIQUE KEY `uq_event_registrations_public_id` (`public_id`),
  KEY `ix_event_registrations_person_id` (`person_id`),
  KEY `ix_event_registrations_invitee_id` (`invitee_id`),
  CONSTRAINT `fk_event_registrations_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_registrations_invitee_id` FOREIGN KEY (`invitee_id`) REFERENCES `event_invitees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_registrations_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_confirmations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `response` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `responded_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_confirmations_registration_id_version` (`registration_id`,`version`),
  CONSTRAINT `fk_event_confirmations_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `event_registrations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_confirmations_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_credentials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `registration_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `token_hash` binary(32) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_credentials_token_hash` (`token_hash`),
  UNIQUE KEY `uq_event_credentials_registration_id_version` (`registration_id`,`version`),
  UNIQUE KEY `uq_event_credentials_public_id` (`public_id`),
  CONSTRAINT `fk_event_credentials_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `event_registrations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_credentials_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_checkins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `registration_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `credential_id` bigint unsigned DEFAULT NULL,
  `event_credential_id` bigint unsigned DEFAULT NULL,
  `checked_at` datetime(6) NOT NULL,
  `device_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `idempotency_request_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
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
  CONSTRAINT `fk_event_checkins_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_credential_id` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_device_id` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_event_credential_id` FOREIGN KEY (`event_credential_id`) REFERENCES `event_credentials` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_idempotency_request_id` FOREIGN KEY (`idempotency_request_id`) REFERENCES `idempotency_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `event_registrations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_checkins_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_attendance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `registration_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `checkin_id` bigint unsigned DEFAULT NULL,
  `attendance_status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_by` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_attendance_session_id_person_id` (`session_id`,`person_id`),
  KEY `ix_event_attendance_registration_id` (`registration_id`),
  KEY `ix_event_attendance_person_id` (`person_id`),
  KEY `ix_event_attendance_checkin_id` (`checkin_id`),
  KEY `ix_event_attendance_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_event_attendance_checkin_id` FOREIGN KEY (`checkin_id`) REFERENCES `event_checkins` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_attendance_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_attendance_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_attendance_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `event_registrations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_attendance_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_counts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `count` int unsigned NOT NULL,
  `method` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_counts_session_id_version` (`session_id`,`version`),
  KEY `ix_event_counts_approved_by` (`approved_by`),
  CONSTRAINT `fk_event_counts_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_counts_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_event_counts_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `communication_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `version` int unsigned NOT NULL,
  `channel` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_communication_templates_code_version_channel` (`code`,`version`,`channel`),
  CONSTRAINT `ck_communication_templates_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audiences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_unit_id` bigint unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `criteria_metadata` json NOT NULL,
  `policy_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_audiences_owner_unit_id` (`owner_unit_id`),
  CONSTRAINT `fk_audiences_owner_unit_id` FOREIGN KEY (`owner_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `communication_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `audience_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_communication_campaigns_public_id` (`public_id`),
  KEY `ix_communication_campaigns_audience_id` (`audience_id`),
  KEY `ix_communication_campaigns_template_id` (`template_id`),
  KEY `ix_communication_campaigns_created_by` (`created_by`),
  CONSTRAINT `fk_communication_campaigns_audience_id` FOREIGN KEY (`audience_id`) REFERENCES `audiences` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_communication_campaigns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_communication_campaigns_template_id` FOREIGN KEY (`template_id`) REFERENCES `communication_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `recipient_person_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned NOT NULL,
  `idempotency_request_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queued_at` datetime(6) NOT NULL,
  `payload_ciphertext` varbinary(2048) DEFAULT NULL,
  `key_version` smallint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messages_idempotency_request_id` (`idempotency_request_id`),
  KEY `ix_messages_recipient_person_id_queued_at` (`recipient_person_id`,`queued_at`),
  KEY `ix_messages_status_queued_at_id` (`status`,`queued_at`,`id`),
  KEY `ix_messages_campaign_id` (`campaign_id`),
  KEY `ix_messages_contact_id` (`contact_id`),
  KEY `ix_messages_template_id` (`template_id`),
  CONSTRAINT `fk_messages_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `communication_campaigns` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `person_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_idempotency_request_id` FOREIGN KEY (`idempotency_request_id`) REFERENCES `idempotency_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_recipient_person_id` FOREIGN KEY (`recipient_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_messages_template_id` FOREIGN KEY (`template_id`) REFERENCES `communication_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invitee_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_event_invitations_invitee_id` (`invitee_id`),
  KEY `ix_event_invitations_message_id` (`message_id`),
  CONSTRAINT `fk_event_invitations_invitee_id` FOREIGN KEY (`invitee_id`) REFERENCES `event_invitees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_event_invitations_message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned DEFAULT NULL,
  `actor_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `department_instance_id` bigint unsigned DEFAULT NULL,
  `before_metadata` json DEFAULT NULL,
  `after_metadata` json DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `session_id` bigint unsigned DEFAULT NULL,
  `ip_hash` binary(32) DEFAULT NULL,
  `correlation_id` char(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_audit_logs_entity_type_entity_id_occurred_at` (`entity_type`,`entity_id`,`occurred_at`),
  KEY `ix_audit_logs_unit_id_occurred_at_id` (`unit_id`,`occurred_at`,`id`),
  KEY `ix_audit_logs_actor_id_occurred_at` (`actor_id`,`occurred_at`),
  KEY `ix_audit_logs_department_instance_id` (`department_instance_id`),
  KEY `ix_audit_logs_session_id` (`session_id`),
  CONSTRAINT `fk_audit_logs_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_audit_logs_department_instance_id` FOREIGN KEY (`department_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_audit_logs_session_id` FOREIGN KEY (`session_id`) REFERENCES `auth_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_audit_logs_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `child_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `owner_unit_id` bigint unsigned NOT NULL,
  `support_notes_ciphertext` varbinary(2048) DEFAULT NULL,
  `key_version` smallint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_child_profiles_person_id` (`person_id`),
  KEY `ix_child_profiles_owner_unit_id` (`owner_unit_id`),
  CONSTRAINT `fk_child_profiles_owner_unit_id` FOREIGN KEY (`owner_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_profiles_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `guardian_authorizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `child_person_id` bigint unsigned NOT NULL,
  `guardian_person_id` bigint unsigned NOT NULL,
  `relationship_id` bigint unsigned DEFAULT NULL,
  `authorization_kind` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guardian_authorizations_public_id` (`public_id`),
  KEY `ix_guardian_authorizations_child_person_id_starts_at` (`child_person_id`,`starts_at`),
  KEY `ix_guardian_authorizations_guardian_person_id` (`guardian_person_id`),
  KEY `ix_guardian_authorizations_relationship_id` (`relationship_id`),
  KEY `ix_guardian_authorizations_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_guardian_authorizations_child_person_id` FOREIGN KEY (`child_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_guardian_authorizations_guardian_person_id` FOREIGN KEY (`guardian_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_guardian_authorizations_relationship_id` FOREIGN KEY (`relationship_id`) REFERENCES `person_relationships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_guardian_authorizations_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_guardian_authorizations_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_consents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_person_id` bigint unsigned NOT NULL,
  `given_by_person_id` bigint unsigned NOT NULL,
  `purpose` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_consents_public_id` (`public_id`),
  KEY `ix_person_consents_subject_person_id` (`subject_person_id`),
  KEY `ix_person_consents_given_by_person_id` (`given_by_person_id`),
  KEY `ix_person_consents_document_id` (`document_id`),
  CONSTRAINT `fk_person_consents_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_consents_given_by_person_id` FOREIGN KEY (`given_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_consents_subject_person_id` FOREIGN KEY (`subject_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `child_emergency_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `child_person_id` bigint unsigned NOT NULL,
  `contact_person_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `priority` smallint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_child_emergency_contacts_child_person_id` (`child_person_id`),
  KEY `ix_child_emergency_contacts_contact_person_id` (`contact_person_id`),
  KEY `ix_child_emergency_contacts_contact_id` (`contact_id`),
  CONSTRAINT `fk_child_emergency_contacts_child_person_id` FOREIGN KEY (`child_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_emergency_contacts_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `person_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_emergency_contacts_contact_person_id` FOREIGN KEY (`contact_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `child_custody_visits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `child_person_id` bigint unsigned NOT NULL,
  `delivered_by_person_id` bigint unsigned NOT NULL,
  `authorization_id` bigint unsigned NOT NULL,
  `checked_in_at` datetime(6) NOT NULL,
  `checked_in_by` bigint unsigned NOT NULL,
  `collected_by_person_id` bigint unsigned DEFAULT NULL,
  `collection_authorization_id` bigint unsigned DEFAULT NULL,
  `checked_out_at` datetime(6) DEFAULT NULL,
  `checked_out_by` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_child_custody_visits_session_id_child_person_id_checked_in_at` (`session_id`,`child_person_id`,`checked_in_at`),
  KEY `ix_child_custody_visits_child_person_id` (`child_person_id`),
  KEY `ix_child_custody_visits_delivered_by_person_id` (`delivered_by_person_id`),
  KEY `ix_child_custody_visits_authorization_id` (`authorization_id`),
  KEY `ix_child_custody_visits_checked_in_by` (`checked_in_by`),
  KEY `ix_child_custody_visits_collected_by_person_id` (`collected_by_person_id`),
  KEY `ix_child_custody_visits_collection_authorization_id` (`collection_authorization_id`),
  KEY `ix_child_custody_visits_checked_out_by` (`checked_out_by`),
  CONSTRAINT `fk_child_custody_visits_authorization_id` FOREIGN KEY (`authorization_id`) REFERENCES `guardian_authorizations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_checked_in_by` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_checked_out_by` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_child_person_id` FOREIGN KEY (`child_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_collected_by_person_id` FOREIGN KEY (`collected_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_collection_authorization_id` FOREIGN KEY (`collection_authorization_id`) REFERENCES `guardian_authorizations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_delivered_by_person_id` FOREIGN KEY (`delivered_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `age_band_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `min_age_months` int unsigned NOT NULL,
  `max_age_months` int unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_age_band_rules_department_id_version` (`department_id`,`version`),
  CONSTRAINT `fk_age_band_rules_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_age_band_rules_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_transition_recommendations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `from_instance_id` bigint unsigned NOT NULL,
  `to_instance_id` bigint unsigned NOT NULL,
  `rule_id` bigint unsigned NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `decided_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_department_transition_recommendations_person_id` (`person_id`),
  KEY `ix_department_transition_recommendations_from_instance_id` (`from_instance_id`),
  KEY `ix_department_transition_recommendations_to_instance_id` (`to_instance_id`),
  KEY `ix_department_transition_recommendations_rule_id` (`rule_id`),
  KEY `ix_department_transition_recommendations_decided_by` (`decided_by`),
  CONSTRAINT `fk_department_transition_recommendations_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_transition_recommendations_from_instance_id` FOREIGN KEY (`from_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_transition_recommendations_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_transition_recommendations_rule_id` FOREIGN KEY (`rule_id`) REFERENCES `age_band_rules` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_transition_recommendations_to_instance_id` FOREIGN KEY (`to_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `outreach_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outreach_campaigns_public_id` (`public_id`),
  KEY `ix_outreach_campaigns_unit_id` (`unit_id`),
  KEY `ix_outreach_campaigns_event_id` (`event_id`),
  CONSTRAINT `fk_outreach_campaigns_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outreach_campaigns_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `outreach_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `assigned_to_person_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outreach_contacts_campaign_id_person_id` (`campaign_id`,`person_id`),
  KEY `ix_outreach_contacts_person_id` (`person_id`),
  KEY `ix_outreach_contacts_assigned_to_person_id` (`assigned_to_person_id`),
  CONSTRAINT `fk_outreach_contacts_assigned_to_person_id` FOREIGN KEY (`assigned_to_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outreach_contacts_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `outreach_campaigns` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outreach_contacts_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `followups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `performed_by_person_id` bigint unsigned NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `outcome` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes_ciphertext` varbinary(2048) DEFAULT NULL,
  `key_version` smallint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_followups_contact_id_occurred_at` (`contact_id`,`occurred_at`),
  KEY `ix_followups_performed_by_person_id` (`performed_by_person_id`),
  CONSTRAINT `fk_followups_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `outreach_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_followups_performed_by_person_id` FOREIGN KEY (`performed_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `decisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `decision_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_on` date DEFAULT NULL,
  `date_precision` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_decisions_person_id` (`person_id`),
  KEY `ix_decisions_contact_id` (`contact_id`),
  KEY `ix_decisions_document_id` (`document_id`),
  CONSTRAINT `fk_decisions_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `outreach_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_decisions_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_decisions_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `discipleship_tracks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `version` int unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discipleship_tracks_code_version` (`code`,`version`),
  CONSTRAINT `ck_discipleship_tracks_version` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `discipleship_enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` bigint unsigned NOT NULL,
  `track_id` bigint unsigned NOT NULL,
  `mentor_person_id` bigint unsigned DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discipleship_enrollments_public_id` (`public_id`),
  KEY `ix_discipleship_enrollments_person_id_starts_at` (`person_id`,`starts_at`),
  KEY `ix_discipleship_enrollments_track_id` (`track_id`),
  KEY `ix_discipleship_enrollments_mentor_person_id` (`mentor_person_id`),
  KEY `ix_discipleship_enrollments_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_discipleship_enrollments_mentor_person_id` FOREIGN KEY (`mentor_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_discipleship_enrollments_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_discipleship_enrollments_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_discipleship_enrollments_track_id` FOREIGN KEY (`track_id`) REFERENCES `discipleship_tracks` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_discipleship_enrollments_period` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `membership_id` bigint unsigned DEFAULT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_integration_events_person_id` (`person_id`),
  KEY `ix_integration_events_unit_id` (`unit_id`),
  KEY `ix_integration_events_membership_id` (`membership_id`),
  KEY `ix_integration_events_document_id` (`document_id`),
  CONSTRAINT `fk_integration_events_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_integration_events_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_integration_events_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_integration_events_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `eligibility_policy_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courses_code` (`code`),
  UNIQUE KEY `uq_courses_public_id` (`public_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `discipleship_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `track_id` bigint unsigned NOT NULL,
  `sequence` int unsigned NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discipleship_steps_track_id_sequence` (`track_id`,`sequence`),
  KEY `ix_discipleship_steps_course_id` (`course_id`),
  CONSTRAINT `fk_discipleship_steps_course_id` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_discipleship_steps_track_id` FOREIGN KEY (`track_id`) REFERENCES `discipleship_tracks` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `discipleship_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint unsigned NOT NULL,
  `step_id` bigint unsigned NOT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discipleship_progress_enrollment_id_step_id` (`enrollment_id`,`step_id`),
  KEY `ix_discipleship_progress_step_id` (`step_id`),
  CONSTRAINT `fk_discipleship_progress_enrollment_id` FOREIGN KEY (`enrollment_id`) REFERENCES `discipleship_enrollments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_discipleship_progress_step_id` FOREIGN KEY (`step_id`) REFERENCES `discipleship_steps` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
