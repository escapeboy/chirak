<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Answers of the base model and an adapter to the run's held-out tickets, through the playground's /predict. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holdout_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('iteration')->nullable(); // null: base model
            $table->string('model');
            $table->unsignedTinyInteger('position');
            $table->text('input');
            $table->json('expected');
            $table->text('raw')->nullable();
            $table->json('parsed')->nullable();
            $table->boolean('valid');
            $table->string('error')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'iteration', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holdout_predictions');
    }
};
