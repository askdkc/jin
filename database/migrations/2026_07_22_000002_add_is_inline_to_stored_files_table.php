<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->boolean('is_inline')->default(false)->after('attachable_id');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropColumn('is_inline');
        });
    }
};
