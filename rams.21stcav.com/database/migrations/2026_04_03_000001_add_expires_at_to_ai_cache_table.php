<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_cache') && ! Schema::hasColumn('ai_cache', 'expires_at')) {
            Schema::table('ai_cache', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->after('created_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_cache') && Schema::hasColumn('ai_cache', 'expires_at')) {
            Schema::table('ai_cache', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }
};
