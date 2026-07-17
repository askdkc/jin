<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todo_lists', function (Blueprint $table): void {
            $table->dropColumn('hill_position');
        });

        Schema::table('todos', function (Blueprint $table): void {
            $table->json('completion_notify_ids')->nullable()->after('assignee_id');
        });
    }

    public function down(): void
    {
        Schema::table('todo_lists', function (Blueprint $table): void {
            $table->unsignedTinyInteger('hill_position')->nullable()->after('is_loose');
        });

        Schema::table('todos', function (Blueprint $table): void {
            $table->dropColumn('completion_notify_ids');
        });
    }
};
