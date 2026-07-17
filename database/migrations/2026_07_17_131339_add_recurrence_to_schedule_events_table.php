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
        Schema::table('schedule_events', function (Blueprint $table) {
            $table->string('recurrence')->nullable()->after('all_day');
            $table->uuid('recurrence_group_id')->nullable()->after('recurrence');
            $table->index('recurrence_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_events', function (Blueprint $table) {
            $table->dropIndex(['recurrence_group_id']);
            $table->dropColumn(['recurrence', 'recurrence_group_id']);
        });
    }
};
