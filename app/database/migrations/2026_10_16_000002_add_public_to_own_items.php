<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // the owner approved this ticket (masked text and label) as an example on the public report
    public function up(): void
    {
        Schema::table('own_items', function (Blueprint $table) {
            $table->boolean('public')->default(false)->after('label_source');
        });
    }

    public function down(): void
    {
        Schema::table('own_items', function (Blueprint $table) {
            $table->dropColumn('public');
        });
    }
};
