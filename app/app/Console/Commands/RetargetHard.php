<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use Illuminate\Console\Command;

/**
 * Resume a finished run with eval-hard as the target slice. Nothing stored is changed: earlier
 * evaluations and clean-slice decisions stay; the loop adds hard-slice decisions and iterations
 * up to the overall maximum.
 */
class RetargetHard extends Command
{
    protected $signature = 'chirak:retarget-hard {slug}';

    protected $description = 'Continue a completed run against its eval-hard set';

    public function handle(RunPipeline $pipeline): int
    {
        $run = Run::where('slug', $this->argument('slug'))->firstOrFail();
        if (! $run->datasets()->where('purpose', 'eval-hard')->exists()) {
            $this->error('the run has no eval-hard dataset (chirak:import-eval-hard first)');

            return self::FAILURE;
        }
        if ($run->status !== Run::COMPLETED) {
            $this->error("run is {$run->status}; only a completed run can be retargeted");

            return self::FAILURE;
        }

        $this->line("was: stop reason \"{$run->stop_reason}\", best iteration {$run->best_iteration}");
        $run->update(['stage' => 'retarget', 'stop_reason' => null]);
        $pipeline->start($run);
        $this->info("{$run->slug} continues with the hard slice as target");

        return self::SUCCESS;
    }
}
