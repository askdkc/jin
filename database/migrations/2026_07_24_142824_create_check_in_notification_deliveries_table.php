<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('check_in_notification_deliveries');
    }
};
