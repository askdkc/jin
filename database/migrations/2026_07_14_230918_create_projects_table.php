<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
            $table->index(['team_id', 'position']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('allows_all_clients')->default(false);
            $table->string('phase', 100)->nullable();
            $table->string('status', 20)->default('on_track')->index();
            $table->json('enabled_tools')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'archived_at']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('project_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('team_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 100);
            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'id'], 'team_audit_team_id_index');
            $table->index(['team_id', 'email', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_audit_logs');
        Schema::dropIfExists('project_favorites');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_folders');
    }
};
