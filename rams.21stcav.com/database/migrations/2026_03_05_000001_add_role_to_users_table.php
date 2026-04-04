<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a 'role' column to the users table.
 *
 * Runs AFTER Laravel's standard 0001_01_01_000000_create_users_table.php
 * because this timestamp is later.
 *
 * Allowed values:  'user'  (default — standard authenticated user)
 *                  'admin' (access to RAMS settings, all documents)
 *
 * To promote a user to admin in tinker:
 *   User::where('email', 'you@example.com')->update(['role' => 'admin']);
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
