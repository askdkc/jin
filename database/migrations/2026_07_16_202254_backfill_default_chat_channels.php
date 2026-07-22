<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('projects')
            ->select(['id', 'created_by'])
            ->orderBy('id')
            ->chunkById(100, function ($projects): void {
                foreach ($projects as $project) {
                    $channelId = DB::table('chat_channels')
                        ->where('project_id', $project->id)
                        ->where('name', 'general')
                        ->value('id');

                    if ($channelId === null) {
                        $channelId = DB::table('chat_channels')->insertGetId([
                            'project_id' => $project->id,
                            'created_by' => $project->created_by,
                            'name' => 'general',
                            'description' => 'プロジェクト全体の会話',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('chat_messages')
                        ->where('project_id', $project->id)
                        ->whereNull('chat_channel_id')
                        ->update(['chat_channel_id' => $channelId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The schema rollback removes these channels after dropping message references.
    }
};
