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
        Schema::table('activities', function (Blueprint $table) {
            $table->index(['actor_id', 'id']);
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->index(['project_id', 'id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index(['material_folder_id', 'project_id']);
        });

        Schema::table('external_links', function (Blueprint $table) {
            $table->index(['material_folder_id', 'project_id']);
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->index(['material_folder_id', 'project_id']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->index(['email', 'accepted_at', 'created_at']);
            $table->index(['team_id', 'email', 'accepted_at', 'expires_at']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->index(['user_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['actor_id', 'id']);
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['material_folder_id', 'project_id']);
        });

        Schema::table('external_links', function (Blueprint $table) {
            $table->dropIndex(['material_folder_id', 'project_id']);
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->dropIndex(['material_folder_id', 'project_id']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropIndex(['email', 'accepted_at', 'created_at']);
            $table->dropIndex(['team_id', 'email', 'accepted_at', 'expires_at']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'team_id']);
        });
    }
};
