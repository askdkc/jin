<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Email verification was configured but inactive before User implemented
        // MustVerifyEmail. Preserve access for those pre-existing accounts.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Irreversible: pre-existing verified and backfilled users cannot be distinguished.
    }
};
