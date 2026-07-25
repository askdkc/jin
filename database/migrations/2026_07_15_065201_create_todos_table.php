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
        Schema::create('todo_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_loose')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'position']);
            $table->index(['project_id', 'is_loose']);
        });

        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('todos')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('completion_notify_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['todo_list_id', 'position']);
            $table->index(['parent_id', 'position']);
            $table->index(['assignee_id', 'completed_at', 'due_on']);
            $table->index(['completed_at', 'due_on', 'id']);
        });

        Schema::create('todo_list_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['todo_list_id', 'user_id']);
        });

        Schema::create('todo_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20)->default('🚀');
            $table->timestamps();

            $table->unique(['todo_id', 'user_id']);
        });

        Schema::create('todo_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('reminder_on');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['todo_id', 'user_id', 'reminder_on']);
            $table->index('user_id');
            $table->index(['reminder_on', 'queued_at', 'skipped_at', 'dispatched_at']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('todo_id')->nullable()->constrained()->nullOnDelete();
            $table->date('worked_on');
            $table->unsignedInteger('minutes');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'worked_on']);
            $table->index(['user_id', 'worked_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('todo_reminder_deliveries');
        Schema::dropIfExists('todo_reactions');
        Schema::dropIfExists('todo_list_user');
        Schema::dropIfExists('todos');
        Schema::dropIfExists('todo_lists');
    }
};
