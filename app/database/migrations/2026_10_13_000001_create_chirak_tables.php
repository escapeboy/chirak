<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('task_spec');
            $table->json('seed_examples');
            $table->json('holdout_examples');
            $table->string('base_model')->nullable();
            $table->string('target_metric')->default('macro_f1');
            $table->decimal('target_value', 4, 3);
            $table->unsignedInteger('eval_n')->default(200);
            $table->unsignedInteger('train_n')->default(1000);
            $table->boolean('require_review')->default(true);
            $table->timestamp('review_approved_at')->nullable();
            $table->string('status')->default('queued');
            $table->string('stage')->nullable();
            $table->text('stop_reason')->nullable();
            $table->string('current_worker_job_id')->nullable();
            $table->string('served_name')->nullable();
            $table->unsignedTinyInteger('best_iteration')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->unsignedTinyInteger('iteration')->nullable();
            $table->string('remote_path');
            $table->string('local_path')->nullable();
            $table->unsignedInteger('n');
            $table->string('sha256', 64);
            $table->timestamp('frozen_at')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'purpose', 'iteration']);
        });

        Schema::create('iterations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('n');
            $table->string('adapter_path')->nullable();
            $table->string('adapter_archive_local')->nullable();
            $table->json('train_config')->nullable();
            $table->json('loss_curve')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'n']);
        });

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('iteration')->nullable();
            $table->string('adapter_path')->nullable();
            $table->json('metrics');
            $table->json('failures')->nullable();
            $table->string('eval_sha256', 64);
            $table->timestamps();
            $table->unique(['run_id', 'iteration']);
        });

        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('iteration');
            $table->json('orchestrator_input')->nullable();
            $table->json('orchestrator_output')->nullable();
            $table->json('next_slices')->nullable();
            $table->json('stop_rule');
            $table->string('model')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'iteration']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('iterations');
        Schema::dropIfExists('datasets');
        Schema::dropIfExists('runs');
    }
};
