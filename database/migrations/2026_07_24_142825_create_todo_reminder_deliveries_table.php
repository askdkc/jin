<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_reminder_deliveries');
    }
};
