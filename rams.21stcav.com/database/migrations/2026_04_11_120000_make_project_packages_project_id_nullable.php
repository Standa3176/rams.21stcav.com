<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make project_packages.project_id nullable so a package can exist
 * in STATUS_EXTRACTING before the project is created by the async job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_packages', function (Blueprint $table) {
            $table->foreignId('project_id')
                  ->nullable()
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_packages', function (Blueprint $table) {
            $table->foreignId('project_id')
                  ->nullable(false)
                  ->change();
        });
    }
};
