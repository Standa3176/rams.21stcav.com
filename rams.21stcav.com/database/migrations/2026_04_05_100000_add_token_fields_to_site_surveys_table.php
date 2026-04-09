<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds secure token-based access fields to site_surveys so that surveys
 * can be shared with engineers via a unique URL without requiring a login.
 *
 * Columns added:
 *   access_token  — UUID v4, generated on model creation, unique.
 *                   Used as the public URL segment: /survey/{token}
 *   expires_at    — Optional expiry for the token. Null = never expires.
 *   submitted_at  — Timestamp set when the engineer submits the final survey.
 *                   Null = survey is still in draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->uuid('access_token')->nullable()->unique()->after('filename');
            $table->timestamp('expires_at')->nullable()->after('access_token');
            $table->timestamp('submitted_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn(['access_token', 'expires_at', 'submitted_at']);
        });
    }
};
