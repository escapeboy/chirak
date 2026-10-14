<?php

namespace App\Console\Commands;

use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Copy runs (rows and their files under storage/app/runs) into a fresh SQLite database for the public
 * read-only demo (deploy/demo). The source database is only read.
 */
class ExportDemo extends Command
{
    protected $signature = 'chirak:export-demo {dir : output directory (gets database.sqlite and storage/app/runs)} {slugs* : runs to export}
        {--served= : override runs.served_name for the demo, as slug=name}';

    protected $description = 'Export runs into a SQLite database for the read-only demo';

    /** child tables with a run_id column, in insert order */
    private const TABLES = ['datasets', 'iterations', 'evaluations', 'decisions', 'holdout_predictions'];

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/');
        $runs = Run::whereIn('slug', $this->argument('slugs'))->get();
        if ($runs->count() !== count($this->argument('slugs'))) {
            $this->error('unknown slug among: '.implode(', ', $this->argument('slugs')));

            return self::FAILURE;
        }

        File::ensureDirectoryExists("$dir/storage/app/runs");
        File::delete("$dir/database.sqlite");
        touch("$dir/database.sqlite");
        config(['database.connections.demo' => ['driver' => 'sqlite', 'database' => "$dir/database.sqlite", 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'demo', '--force' => true]);

        $served = collect((array) $this->option('served'))->filter()
            ->mapWithKeys(fn ($pair) => [explode('=', $pair, 2)[0] => explode('=', $pair, 2)[1]]);
        $demo = DB::connection('demo');
        foreach ($runs as $run) {
            $row = (array) DB::table('runs')->where('id', $run->id)->first();
            if ($served->has($run->slug)) {
                $row['served_name'] = $served[$run->slug];
            }
            $demo->table('runs')->insert($row);
            foreach (self::TABLES as $table) {
                foreach (DB::table($table)->where('run_id', $run->id)->orderBy('id')->get() as $child) {
                    $demo->table($table)->insert((array) $child);
                }
            }
            if (Storage::disk('runs')->exists($run->slug)) {
                File::copyDirectory(Storage::disk('runs')->path($run->slug), "$dir/storage/app/runs/{$run->slug}");
            }
            $this->info("{$run->slug}: exported");
        }

        return self::SUCCESS;
    }
}
