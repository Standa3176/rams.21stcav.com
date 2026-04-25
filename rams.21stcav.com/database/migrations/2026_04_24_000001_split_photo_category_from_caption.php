<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split photo `category` (system enum) out of `caption` (user-supplied text).
 *
 * Historically the `caption` column was overloaded as the photo category
 * ("room_overview", "display_wall", …) so the front-end could group thumbnails.
 * That left no place for engineers to annotate individual photos.
 *
 * After this migration:
 *   - `category` holds the system slug (filtered enum)
 *   - `caption`  holds free-text engineer annotation (nullable)
 *
 * Existing rows have their old caption-as-category copied into `category`,
 * and any `caption` that matches a known category slug is cleared to null
 * so it does not leak into the new annotation UI.
 */
return new class extends Migration
{
    /** Known photo-category slugs surfaced by the survey wizard. */
    private const KNOWN_CATEGORIES = [
        'room_overview',
        'display_wall',
        'ceiling',
        'rack_comms',
        'cable_routes',
        'power_network_points',
    ];

    public function up(): void
    {
        Schema::table('site_survey_photos', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('mime_type');
        });

        DB::statement('UPDATE site_survey_photos SET category = caption WHERE category IS NULL');

        DB::table('site_survey_photos')
            ->whereIn('caption', self::KNOWN_CATEGORIES)
            ->update(['caption' => null]);
    }

    public function down(): void
    {
        Schema::table('site_survey_photos', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
