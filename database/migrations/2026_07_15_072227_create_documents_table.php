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
        Schema::create('material_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'position']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'updated_at']);
            $table->index(['material_folder_id', 'project_id']);
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('attachable');
            $table->boolean('is_inline')->default(false);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64)->nullable();
            $table->string('preview_path')->nullable();
            $table->string('preview_status')->nullable();
            $table->timestamps();

            $table->index(['material_folder_id', 'project_id']);
            $table->index(['project_id', 'updated_at'], 'stored_files_project_updated_index');
        });

        Schema::create('external_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->timestamps();

            $table->index(['project_id', 'updated_at']);
            $table->index(['material_folder_id', 'project_id']);
        });

        Schema::create('stored_file_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stored_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20)->default('🚀');
            $table->timestamps();

            $table->unique(['stored_file_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stored_file_reactions');
        Schema::dropIfExists('external_links');
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('material_folders');
    }
};
