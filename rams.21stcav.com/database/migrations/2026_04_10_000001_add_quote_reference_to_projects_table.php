<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add quote_reference column to projects table.
 *
 * Nullable because existing projects won't have it yet.
 * New projects will require it via ProjectRequest validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('quote_reference')->nullable()->after('ref');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('quote_reference');
        });
    }
};
