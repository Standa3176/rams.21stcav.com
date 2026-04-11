<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->string('site_contact_name',  150)->nullable()->after('surveyor_name');
            $table->string('site_contact_phone',  50)->nullable()->after('site_contact_name');
            $table->string('visit_time',         100)->nullable()->after('site_contact_phone');
            $table->string('pm_name',            150)->nullable()->after('visit_time');
            $table->string('pm_phone',            50)->nullable()->after('pm_name');
            $table->string('pm_email',           150)->nullable()->after('pm_phone');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn(['site_contact_name', 'site_contact_phone', 'visit_time', 'pm_name', 'pm_phone', 'pm_email']);
        });
    }
};
