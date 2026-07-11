<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->text('from_location')->nullable()->change();
            $table->text('to_location')->nullable()->change();
            $table->text('cable_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->string('from_location', 255)->nullable()->change();
            $table->string('to_location', 255)->nullable()->change();
            $table->string('cable_type', 255)->nullable()->change();
        });
    }
};
