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
        Schema::create('check_in_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_for');
            $table->dateTime('requested_at');
            $table->text('answer')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['check_in_id', 'user_id', 'scheduled_for']);
            $table->index(['user_id', 'answered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_prompts');
    }
};
