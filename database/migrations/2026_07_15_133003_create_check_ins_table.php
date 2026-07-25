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
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('question');
            $table->string('frequency', 20);
            $table->string('timezone', 64)->default('Asia/Tokyo');
            $table->dateTime('next_run_at');
            $table->dateTime('paused_at')->nullable();
            $table->json('weekdays')->nullable();
            $table->timestamps();

            $table->index(['paused_at', 'next_run_at']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('check_in_user', function (Blueprint $table) {
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['check_in_id', 'user_id']);
        });

        Schema::create('check_in_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_for');
            $table->dateTime('requested_at');
            $table->text('answer')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();

            $table->index(
                ['check_in_id', 'user_id', 'scheduled_for'],
                'check_in_prompts_schedule_index',
            );
            $table->index(
                ['user_id', 'answered_at', 'scheduled_for'],
                'check_in_user_answered_scheduled_index',
            );
        });

        Schema::create('check_in_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['check_in_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('check_in_prompt_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_prompt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20);
            $table->timestamps();

            $table->unique(['check_in_prompt_id', 'user_id']);
        });

        Schema::create('check_in_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_prompt_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['queued_at', 'skipped_at', 'dispatched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_notification_deliveries');
        Schema::dropIfExists('check_in_prompt_reactions');
        Schema::dropIfExists('check_in_bookmarks');
        Schema::dropIfExists('check_in_prompts');
        Schema::dropIfExists('check_in_user');
        Schema::dropIfExists('check_ins');
    }
};
