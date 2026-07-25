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
        Schema::create('personal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamps();

            $table->index(['team_id', 'user_id', 'updated_at']);
        });

        Schema::create('personal_note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_note_id')
                ->nullable()
                ->constrained('personal_notes')
                ->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['team_id', 'uploaded_by', 'created_at']);
            $table->index(['personal_note_id', 'created_at']);
        });

        Schema::create('bubble_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('url', 2048);
            $table->timestamp('remind_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'team_id', 'completed_at', 'remind_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bubble_ups');
        Schema::dropIfExists('personal_note_attachments');
        Schema::dropIfExists('personal_notes');
    }
};
