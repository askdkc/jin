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
        Schema::create('schedule_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->string('meeting_url', 2048)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('recurrence')->nullable();
            $table->uuid('recurrence_group_id')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'starts_at']);
            $table->index('starts_at');
            $table->index('recurrence_group_id');
        });

        Schema::create('schedule_event_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20)->default('🚀');
            $table->timestamps();

            $table->unique(
                ['schedule_event_id', 'user_id'],
                'schedule_event_reactions_event_user_unique',
            );
        });

        Schema::create('calendar_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'user_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_subscriptions');
        Schema::dropIfExists('schedule_event_reactions');
        Schema::dropIfExists('schedule_events');
    }
};
