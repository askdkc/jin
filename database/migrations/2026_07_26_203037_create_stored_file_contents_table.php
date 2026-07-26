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
        Schema::create('stored_file_contents', function (Blueprint $table) {
            $table->foreignId('stored_file_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('source_checksum', 64);
            $table->string('status', 20)->default('pending');
            $table->string('extractor', 30)->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('content_bytes')->default(0);
            $table->boolean('is_truncated')->default(false);
            $table->string('failure_code', 50)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->createSqliteIndex(),
            'pgsql' => $this->createPostgresIndex(),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS stored_file_contents_fts_insert');
            DB::statement('DROP TRIGGER IF EXISTS stored_file_contents_fts_update');
            DB::statement('DROP TRIGGER IF EXISTS stored_file_contents_fts_delete');
            DB::statement('DROP TABLE IF EXISTS stored_file_contents_fts');
        }

        Schema::dropIfExists('stored_file_contents');
    }

    private function createSqliteIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE stored_file_contents_fts USING fts5(
                content,
                content = 'stored_file_contents',
                content_rowid = 'stored_file_id',
                tokenize = 'trigram case_sensitive 0'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER stored_file_contents_fts_insert
            AFTER INSERT ON stored_file_contents
            WHEN new.content IS NOT NULL
            BEGIN
                INSERT INTO stored_file_contents_fts(rowid, content)
                VALUES (new.stored_file_id, new.content);
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER stored_file_contents_fts_update
            AFTER UPDATE OF content ON stored_file_contents
            BEGIN
                INSERT INTO stored_file_contents_fts(stored_file_contents_fts, rowid, content)
                SELECT 'delete', old.stored_file_id, old.content
                WHERE old.content IS NOT NULL;

                INSERT INTO stored_file_contents_fts(rowid, content)
                SELECT new.stored_file_id, new.content
                WHERE new.content IS NOT NULL;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER stored_file_contents_fts_delete
            AFTER DELETE ON stored_file_contents
            WHEN old.content IS NOT NULL
            BEGIN
                INSERT INTO stored_file_contents_fts(stored_file_contents_fts, rowid, content)
                VALUES ('delete', old.stored_file_id, old.content);
            END
        SQL);
    }

    private function createPostgresIndex(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgroonga');
        DB::statement(<<<'SQL'
            CREATE INDEX stored_file_contents_content_pgroonga_index
            ON stored_file_contents
            USING pgroonga (content)
            WHERE status = 'ready' AND content IS NOT NULL
        SQL);
    }
};
