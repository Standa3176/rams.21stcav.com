<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two targeted improvements to rams_documents:
 *
 *  1. status enum ('pending'|'complete'|'failed')
 *     Tracks whether the .docx build succeeded after the DB record was created.
 *     A 'pending' record indicates the build either did not finish or crashed;
 *     'failed' means it threw an exception; 'complete' is the happy path.
 *
 *  2. site_address changed from string (varchar 255) to text
 *     UK site addresses can exceed 255 characters when including building names,
 *     floor details, and full postcodes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            // Add after 'filename' — order keeps the column near related fields
            $table->enum('status', ['pending', 'complete', 'failed'])
                  ->default('pending')
                  ->after('filename');

            $table->text('site_address')->change();
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->string('site_address')->change();
        });
    }
};
