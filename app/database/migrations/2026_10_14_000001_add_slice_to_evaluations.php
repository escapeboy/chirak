<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A run can now be scored on two eval sets: the frozen eval ("clean" slice) and eval-hard ("hard" slice,
 * human-arbitrated). Existing rows are clean-slice scores of the run's frozen eval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->string('slice', 16)->default('clean')->after('iteration');
            $table->foreignId('dataset_id')->nullable()->after('run_id')->constrained()->nullOnDelete();
        });
        DB::table('evaluations')->orderBy('id')->each(function ($e) {
            $id = DB::table('datasets')->where('run_id', $e->run_id)->where('purpose', 'eval')->where('sha256', $e->eval_sha256)->value('id');
            DB::table('evaluations')->where('id', $e->id)->update(['dataset_id' => $id]);
        });
        Schema::table('evaluations', function (Blueprint $table) {
            $table->unique(['run_id', 'slice', 'iteration']);
            $table->dropUnique(['run_id', 'iteration']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->unique(['run_id', 'iteration']);
            $table->dropUnique(['run_id', 'slice', 'iteration']);
            $table->dropConstrainedForeignId('dataset_id');
            $table->dropColumn('slice');
        });
    }
};
