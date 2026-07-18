<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('voice_notes')) {
            foreach (DB::table('voice_notes')->select(['disk', 'path'])->distinct()->cursor() as $voiceNote) {
                throw_unless(
                    Storage::disk($voiceNote->disk)->delete($voiceNote->path),
                    RuntimeException::class,
                    "Failed to delete voice note {$voiceNote->path}.",
                );
            }
        }

        Schema::dropIfExists('voice_notes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('voice_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });
    }
};
