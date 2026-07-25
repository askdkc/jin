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
        Schema::create('card_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        Schema::create('card_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_table_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_collapsed')->default(false);
            $table->boolean('is_triage')->default(false);
            $table->boolean('is_not_now')->default(false);
            $table->boolean('is_done')->default(false);
            $table->timestamps();

            $table->index(['card_table_id', 'position']);
            $table->index(['card_table_id', 'is_triage']);
            $table->index(['card_table_id', 'is_not_now']);
            $table->index(['card_table_id', 'is_done']);
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['card_column_id', 'position']);
            $table->index(['assignee_id', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
        Schema::dropIfExists('card_columns');
        Schema::dropIfExists('card_tables');
    }
};
