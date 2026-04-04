<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            $table->json('generated_data')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            $table->json('generated_data')->nullable(false)->change();
        });
    }
};
