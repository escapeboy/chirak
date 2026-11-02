<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // judge | human | claude-code-delegated (a review done by an agent on the owner's delegation)
    public function up(): void
    {
        Schema::table('own_items', function (Blueprint $table) {
            $table->string('label_source', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('own_items', function (Blueprint $table) {
            $table->string('label_source', 8)->nullable()->change();
        });
    }
};
