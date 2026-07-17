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
            $table->timestamps();

            $table->index(['paused_at', 'next_run_at']);
        });

        Schema::create('check_in_user', function (Blueprint $table) {
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['check_in_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_user');
        Schema::dropIfExists('check_ins');
    }
};
