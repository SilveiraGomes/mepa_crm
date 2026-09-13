<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: materializes W1-F01. department_instances now exists; the FK deferred in Wave 1
// (files.owner_department_id) is added here, before any writer of that column is authorized.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE `files`
  ADD CONSTRAINT `fk_files_owner_department_id` FOREIGN KEY (`owner_department_id`)
  REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `files` DROP FOREIGN KEY `fk_files_owner_department_id`');
    }
};
