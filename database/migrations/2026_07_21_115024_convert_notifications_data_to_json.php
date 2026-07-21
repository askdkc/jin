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
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE JSON USING data::json'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL'),
            // SQLite and SQL Server already expose JSON operators over their text representation.
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE TEXT USING data::text'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data LONGTEXT NOT NULL'),
            default => null,
        };
    }
};
