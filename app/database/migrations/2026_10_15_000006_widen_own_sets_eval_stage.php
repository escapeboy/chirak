<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // holds the served adapter name (as long as runs.served_name), e.g. store-review-triage-bg-2cri7b-iter-1
    public function up(): void
    {
        Schema::table('own_sets', function (Blueprint $table) {
            $table->string('eval_stage')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('own_sets', function (Blueprint $table) {
            $table->string('eval_stage', 32)->nullable()->change();
        });
    }
};
