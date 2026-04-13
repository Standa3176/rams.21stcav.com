<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the install_tasks table for the Installation Programme module.
 *
 * Each task represents a single unit of work within an install programme:
 * one piece of equipment in one room, with a task type (install, configure,
 * cable, test, commission). room_name is denormalised — it is NOT a FK to
 * site_survey_rooms because IDs may not match survey room names exactly;
 * ProjectDataService resolves rooms from reviewed_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * install_tasks — individual work items within an install programme.
         *
         * Status values: pending | in_progress | complete | blocked | skipped
         * Task type values: install | configure | cable | test | commission
         * room_name is a denormalised string — see class PHPDoc for rationale.
         */
        Schema::create('install_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('install_programme_id')
                  ->constrained('install_programmes')
                  ->cascadeOnDelete();

            $table->string('room_name', 200);
            // Denormalised — NOT a FK to site_survey_rooms. ProjectDataService
            // resolves rooms from reviewed_data; IDs may not match survey room
            // names exactly.

            $table->string('room_ref', 50)->nullable();
            $table->string('equipment_name', 300);
            $table->string('equipment_category', 100)->default('hardware');

            $table->string('task_type', 30)->default('install');
            // install | configure | cable | test | commission

            $table->string('title', 500);
            $table->text('description')->nullable();

            $table->string('status', 30)->default('pending');
            // pending | in_progress | complete | blocked | skipped

            $table->text('blocked_reason')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->foreignId('assigned_to')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->boolean('sign_off_required')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('install_programme_id');
            $table->index(['room_name', 'sort_order']);
            $table->index('status');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_tasks');
    }
};
