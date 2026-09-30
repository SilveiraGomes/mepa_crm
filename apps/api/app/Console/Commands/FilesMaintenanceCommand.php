<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Files\FilesMaintenance;
use App\Http\Files\FilesServiceFactory;
use Illuminate\Console\Command;

// ADR 0019 Cron (no resident worker): reconciliation of abandoned QUARANTINED custody + orphan staging objects,
// integrity sampling of AVAILABLE objects, and the operational key re-wrap. Output carries counters only.
final class FilesMaintenanceCommand extends Command
{
    protected $signature = 'files:maintain {task : reconcile|verify|rewrap} {--sample=20} {--limit=100}';
    protected $description = 'Documents/Files maintenance (reconcile quarantine, verify integrity sample, re-wrap keys)';

    public function handle(FilesServiceFactory $factory): int
    {
        $maintenance = new FilesMaintenance($factory->runtime());
        $report = match ((string) $this->argument('task')) {
            'reconcile' => $maintenance->reconcile(),
            'verify' => $maintenance->verifySample((int) $this->option('sample')),
            'rewrap' => $maintenance->rewrap((int) $this->option('limit')),
            default => null,
        };
        if ($report === null) {
            $this->error('unknown task');
            return self::INVALID;
        }
        $this->line((string) json_encode($report));
        return self::SUCCESS;
    }
}
