<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_note_attachments', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('personal_note_attachments')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException(
                'Cannot remove personal_note_attachments.deleted_at while soft-deleted attachments are pending physical cleanup. Run personal-note-attachments:prune and drain the queue before retrying.',
            );
        }

        Schema::table('personal_note_attachments', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
