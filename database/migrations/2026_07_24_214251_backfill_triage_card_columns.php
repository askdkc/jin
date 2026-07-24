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
        DB::table('card_tables')
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($cardTables): void {
                $timestamp = now();

                DB::table('card_columns')->insert(
                    $cardTables->map(fn (object $cardTable): array => [
                        'card_table_id' => $cardTable->id,
                        'name' => 'Triage',
                        'position' => 0,
                        'is_collapsed' => false,
                        'is_triage' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('card_columns')->where('is_triage', true)->delete();
    }
};
