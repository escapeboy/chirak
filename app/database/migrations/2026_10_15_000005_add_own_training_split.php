<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // part of the uploaded tickets can go to training: the split is made at upload; a follow-up run remembers its source run
    public function up(): void
    {
        Schema::table('own_items', function (Blueprint $table) {
            $table->string('split', 8)->default('eval')->after('position');
        });
        Schema::table('own_sets', function (Blueprint $table) {
            $table->unsignedTinyInteger('train_share')->default(0)->after('state');
        });
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('trained_from_run_id')->nullable()->unique()->after('error')->constrained('runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trained_from_run_id');
        });
        Schema::table('own_sets', function (Blueprint $table) {
            $table->dropColumn('train_share');
        });
        Schema::table('own_items', function (Blueprint $table) {
            $table->dropColumn('split');
        });
    }
};
