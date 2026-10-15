<?php

// Creates the store-review run the same way the wizard does (App\Livewire\CreateRun::start) and starts its pipeline.
// php app/artisan tinker --execute "require 'demo/bgstores/create_run.php';"   with BGSTORES_DIR=<dir with seeds.jsonl, holdout.jsonl>

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use App\Support\ExamplesParser;
use App\Support\TaskSpec;
use Illuminate\Support\Str;

$task = json_decode(file_get_contents(__DIR__.'/task.json'), true);
$dir = getenv('BGSTORES_DIR');
$spec = ['name' => Str::slug($task['name']), 'description' => $task['description'], 'languages' => $task['languages'],
    'fields' => $task['fields'], 'guidelines' => $task['guidelines']];
if ($errors = TaskSpec::fieldErrors($spec['fields'])) {
    throw new RuntimeException(implode('; ', $errors));
}
$seeds = ExamplesParser::parse(file_get_contents("$dir/seeds.jsonl"), $spec);
$holdout = ExamplesParser::parse(file_get_contents("$dir/holdout.jsonl"), $spec);
if ($seeds['errors'] || $holdout['errors'] || array_intersect(array_column($seeds['items'], 'input'), array_column($holdout['items'], 'input'))) {
    throw new RuntimeException('bad seeds or hold-outs: '.json_encode(array_merge($seeds['errors'], $holdout['errors'])));
}

$run = Run::create([
    'slug' => Str::slug($task['name']).'-'.Str::lower(Str::random(6)),
    'name' => $task['name'],
    'task_spec' => $spec,
    'seed_examples' => $seeds['items'],
    'holdout_examples' => $holdout['items'],
    'target_metric' => $task['target_metric'],
    'target_value' => $task['target_value'],
    'eval_n' => $task['eval_n'],
    'train_n' => $task['train_n'],
    'require_review' => $task['require_review'],
    'status' => Run::QUEUED,
]);
app(RunPipeline::class)->start($run);
echo "created {$run->slug}\n";
