<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('material_folder_id')
                ->nullable()
                ->after('project_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->foreignId('material_folder_id')
                ->nullable()
                ->after('project_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_folder_id');
        });

        Schema::table('stored_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_folder_id');
        });
    }
};
