<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\UserImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportUsers extends Command
{
    protected $signature = 'herasat:import-users
        {file : Path to a .xlsx or .csv file}
        {--sheet= : Sheet name (default: first sheet containing the required columns)}
        {--update : Update profile fields of users that already exist (passwords are never touched)}
        {--dry-run : Validate only, do not write anything}';

    protected $description = 'Import users (personnel code = username, national code = initial password) from XLSX/CSV';

    public function handle(UserImportService $importer): int
    {
        $bar = null;
        try {
            $bar = $this->output->createProgressBar();
            $result = $importer->import($this->argument('file'), [
                'sheet' => $this->option('sheet'),
                'update' => (bool) $this->option('update'),
                'dry_run' => (bool) $this->option('dry-run'),
                'progress' => fn () => $bar->advance(),
            ]);
            $bar->finish();
            $this->newLine(2);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $errors = $result['errors'];
        unset($result['errors']);
        $this->table(['metric', 'count'], collect($result)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($errors) {
            $this->warn('First problems (row number / reason):');
            foreach (array_slice($errors, 0, 30) as $e) {
                $this->line("  row {$e['row']}: {$e['reason']}");
            }
        }
        if (! $this->option('dry-run')) {
            AuditLogger::log('user.import_cli', null, $result);
        }

        return self::SUCCESS;
    }
}
