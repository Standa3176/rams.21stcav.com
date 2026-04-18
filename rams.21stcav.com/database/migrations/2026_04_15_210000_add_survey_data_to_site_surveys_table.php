<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the survey_data JSON column to site_surveys.
 *
 * This column stores the complete structured survey payload assembled
 * by the step-based mobile wizard, in the canonical shape:
 *
 *   {
 *     "project_id": int,
 *     "rooms": [
 *       {
 *         "name": string,   "type": string,
 *         "photos": [],
 *         "infrastructure": { "power": {}, "network": {}, "cable_routes": {} },
 *         "equipment": [],
 *         "risks": [],
 *         "notes": ""
 *       }
 *     ]
 *   }
 *
 * The column is nullable so existing surveys without wizard data are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->json('survey_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn('survey_data');
        });
    }
};
