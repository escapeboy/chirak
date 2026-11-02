<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop decisions are made against one eval set: "clean" (the frozen eval) or, once a run has eval-hard,
 * "hard". Existing decisions were made on the clean slice and stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->string('slice', 16)->default('clean')->after('iteration');
        });
        Schema::table('decisions', function (Blueprint $table) {
            $table->unique(['run_id', 'slice', 'iteration']);
            $table->dropUnique(['run_id', 'iteration']);
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->unique(['run_id', 'iteration']);
            $table->dropUnique(['run_id', 'slice', 'iteration']);
            $table->dropColumn('slice');
        });
    }
};
