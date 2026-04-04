<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('rams_documents', function (Blueprint $table) {
                $table->string('status', 50)->default('for_review');
            });
        } catch (\Throwable $e) {
            // Ignore duplicate column errors (SQLite test environment)
        }
    }

    public function down(): void
    {
        try {
            Schema::table('rams_documents', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        } catch (\Throwable $e) {
            // Ignore if column doesn't exist
        }
    }
};
