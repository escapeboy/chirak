<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // set once the worker confirmed it removed its copy of the frozen file and the eval-own results
    public function up(): void
    {
        Schema::table('own_sets', function (Blueprint $table) {
            $table->timestamp('worker_copy_deleted_at')->nullable()->after('items_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('own_sets', function (Blueprint $table) {
            $table->dropColumn('worker_copy_deleted_at');
        });
    }
};
