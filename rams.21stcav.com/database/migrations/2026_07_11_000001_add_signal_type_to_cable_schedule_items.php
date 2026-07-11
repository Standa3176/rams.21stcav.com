<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->string('signal_type', 20)->nullable()->after('cable_type');
        });
    }

    public function down(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->dropColumn('signal_type');
        });
    }
};
