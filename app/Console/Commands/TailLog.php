<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TailLog extends Command
{
    protected $signature = 'log:tail {--lines=80} {--errors-only}';
    protected $description = 'Tail storage/logs/laravel.log via artisan (works through railway ssh).';

    public function handle(): int
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            $this->error("No log file at {$path}");
            return self::FAILURE;
        }

        $lines = (int) $this->option('lines');
        $contents = trim((string) file_get_contents($path));
        $rows = explode("\n", $contents);

        if ($this->option('errors-only')) {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains($r, 'production.ERROR') || str_contains($r, 'production.CRITICAL') || str_contains($r, '[previous exception]')));
        }

        $tail = array_slice($rows, -$lines);
        foreach ($tail as $r) {
            $this->line($r);
        }

        return self::SUCCESS;
    }
}
