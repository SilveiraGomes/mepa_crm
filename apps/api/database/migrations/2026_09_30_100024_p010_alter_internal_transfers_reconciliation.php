<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.10-F1B decision F1B-D1 (refines ADR 0021 D10 / D-04A.5): interunit reconciliation is an EXPLICIT verification step
// (FINANCE_RECONCILE) after RECEIVE, no longer implied by the reception itself. No state is added: the transfer status
// stays DRAFT / SENT / RECEIVED / CANCELLED; `reconciled_at` (catalog column) records the verified pairing.
//   ck_internal_transfers_received   RECEIVED <=> received_at AND destination_account_id (reconciled_at removed from it)
//   ck_internal_transfers_reconciled reconciled_at only on a RECEIVED transfer, never before its reception
// One atomic ALTER (MySQL applies it as one DDL). The new pair is strictly weaker for existing RECEIVED rows, so up()
// never rejects valid data; down() refuses (changing nothing) while a RECEIVED transfer is still unreconciled.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        $invalid = DB::table('internal_transfers')->whereNotNull('reconciled_at')->where(fn ($q) => $q->where('status', '<>', 'RECEIVED')->orWhereColumn('reconciled_at', '<', 'received_at'))->count();
        if ($invalid > 0) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: ' . $invalid . ' transfer(s) reconciled without a valid reception; nothing changed.');
        }
        DB::statement(<<<'SQL'
ALTER TABLE `internal_transfers`
  DROP CHECK `ck_internal_transfers_received`,
  ADD CONSTRAINT `ck_internal_transfers_received` CHECK ((`status` = 'RECEIVED') = (`received_at` IS NOT NULL) AND (`status` = 'RECEIVED') = (`destination_account_id` IS NOT NULL)),
  ADD CONSTRAINT `ck_internal_transfers_reconciled` CHECK (`reconciled_at` IS NULL OR (`status` = 'RECEIVED' AND `reconciled_at` >= `received_at`))
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('internal_transfers')->where('status', 'RECEIVED')->whereNull('reconciled_at')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: RECEIVED transfers awaiting reconciliation exist; nothing changed.');
        }
        DB::statement(<<<'SQL'
ALTER TABLE `internal_transfers`
  DROP CHECK `ck_internal_transfers_reconciled`,
  DROP CHECK `ck_internal_transfers_received`,
  ADD CONSTRAINT `ck_internal_transfers_received` CHECK ((`status` = 'RECEIVED') = (`received_at` IS NOT NULL) AND (`status` = 'RECEIVED') = (`destination_account_id` IS NOT NULL) AND (`status` = 'RECEIVED') = (`reconciled_at` IS NOT NULL))
SQL
        );
    }
};
