<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 44 Plan 01 Task 1 — foundation schema for labour resources
 * (44-CONTEXT.md D-01, D-02).
 *
 * D-01 ("one table with a role field, not separate engineer and programmer
 * tables"): a person may hold more than one role, so `roles` is a single
 * JSON column on one row rather than a second row per role or a pivot
 * table. A person is one row, always.
 *
 * D-02 ("add, edit and deactivate — never hard-delete"): `is_active`
 * defaults to true at the database level and is indexed, so admin's
 * "deactivate" action is an UPDATE, never a DELETE. A resource that has
 * attended visits must keep displaying correctly on that history — there is
 * no delete path anywhere in this schema or the model built on top of it.
 *
 * `user_id` is a nullable FK with `nullOnDelete()` — per CONTEXT.md's
 * "Claude's Discretion" note, engineers are not `User` rows today
 * (App\Models\User only models staff logins), and D-06 keeps free-text
 * `captured_by` names un-linked. A nullable link lets admin optionally point
 * a resource at an existing login without requiring every engineer to have
 * one. Deleting a User must never cascade-delete a labour resource or its
 * history, hence `nullOnDelete()` rather than `cascadeOnDelete()`.
 *
 * No cost or day-rate column — explicitly deferred by the user on
 * 2026-09-19 (CONTEXT.md Deferred Ideas). No email/phone uniqueness
 * constraint — not asked for by any decision.
 *
 * @see app/Models/LabourResource.php
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-01, D-02)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_resources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->json('roles');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_resources');
    }
};
