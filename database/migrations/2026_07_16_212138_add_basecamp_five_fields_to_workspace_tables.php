<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('todo_lists', function (Blueprint $table) {
            $table->boolean('is_loose')->default(false)->after('title');
            $table->unsignedTinyInteger('hill_position')->nullable()->after('is_loose');
            $table->index(['project_id', 'is_loose']);
        });

        Schema::table('card_columns', function (Blueprint $table) {
            $table->boolean('is_collapsed')->default(false)->after('position');
        });

        Schema::table('schedule_events', function (Blueprint $table) {
            $table->string('meeting_url', 2048)->nullable()->after('details');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('body');
            $table->timestamp('published_at')->nullable()->after('public_token');
        });

        DB::table('projects')
            ->orderBy('id')
            ->chunkById(100, function ($projects): void {
                $now = now();
                $rows = collect($projects)->map(fn (object $project): array => [
                    'project_id' => $project->id,
                    'title' => '未分類の任務',
                    'is_loose' => true,
                    'hill_position' => null,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('todo_lists')->insert($rows);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'published_at']);
        });

        Schema::table('schedule_events', function (Blueprint $table) {
            $table->dropColumn('meeting_url');
        });

        Schema::table('card_columns', function (Blueprint $table) {
            $table->dropColumn('is_collapsed');
        });

        Schema::table('todo_lists', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'is_loose']);
            $table->dropColumn(['is_loose', 'hill_position']);
        });
    }
};
