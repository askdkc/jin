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
        Schema::table('schedule_events', function (Blueprint $table) {
            $table->index('starts_at');
        });

        Schema::table('direct_messages', function (Blueprint $table) {
            // User-leading keys let SQLite merge both sides of the sender/recipient OR scan.
            $table->index(['sender_id', 'team_id', 'id']);
            $table->index(['recipient_id', 'team_id', 'id']);
            $table->dropIndex(['team_id', 'sender_id', 'recipient_id', 'id']);
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->index(['completed_at', 'due_on', 'id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('parent_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->dropIndex(['notifiable_type', 'notifiable_id']);
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->index(['project_id', 'updated_at'], 'stored_files_project_updated_index');
            $table->dropIndex(['project_id', 'created_at']);
        });

        Schema::table('team_audit_logs', function (Blueprint $table) {
            $table->index(['team_id', 'id'], 'team_audit_team_id_index');
            $table->dropIndex(['team_id', 'created_at']);
        });

        Schema::table('check_in_prompts', function (Blueprint $table) {
            $table->index(
                ['user_id', 'answered_at', 'scheduled_for'],
                'check_in_user_answered_scheduled_index',
            );
            $table->dropIndex(['user_id', 'answered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_in_prompts', function (Blueprint $table) {
            $table->index(['user_id', 'answered_at']);
            $table->dropIndex('check_in_user_answered_scheduled_index');
        });

        Schema::table('team_audit_logs', function (Blueprint $table) {
            $table->index(['team_id', 'created_at']);
            $table->dropIndex('team_audit_team_id_index');
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->index(['project_id', 'created_at']);
            $table->dropIndex('stored_files_project_updated_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'created_at']);
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['completed_at', 'due_on', 'id']);
        });

        Schema::table('direct_messages', function (Blueprint $table) {
            $table->index(['team_id', 'sender_id', 'recipient_id', 'id']);
            $table->dropIndex(['sender_id', 'team_id', 'id']);
            $table->dropIndex(['recipient_id', 'team_id', 'id']);
        });

        Schema::table('schedule_events', function (Blueprint $table) {
            $table->dropIndex(['starts_at']);
        });
    }
};
