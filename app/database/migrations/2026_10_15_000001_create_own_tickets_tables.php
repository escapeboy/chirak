<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A shop's own tickets for one run: masked texts, judge proposals, human decisions, and the eval built from them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('own_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('labelling'); // labelling | review | frozen
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->string('eval_state', 16)->nullable(); // pending | running | done | failed
            $table->string('eval_stage', 32)->nullable();
            $table->string('worker_job_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('items_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('own_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text'); // masked, never the original
            $table->string('status', 16)->default('new'); // new | labelled | unstable | dropped
            $table->json('passes')->nullable(); // the judge's two parsed answers, null where a pass gave none
            $table->json('label')->nullable();
            $table->string('label_source', 8)->nullable(); // judge | human
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'position']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('own_items');
        Schema::dropIfExists('own_sets');
    }
};
