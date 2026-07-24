<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;

#[Signature('jin:mcp {user : Email address of the Jin user represented by this local MCP process}')]
#[Description('Start the Jin MCP server as a fixed authenticated user')]
class StartJinMcp extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('user')));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error('The requested Jin user was not found.');

            return self::FAILURE;
        }

        $server = Mcp::getLocalServer('jin');

        if ($server === null) {
            $this->components->error('The Jin MCP server is not registered.');

            return self::FAILURE;
        }

        Auth::setUser($user);
        $server();

        return self::SUCCESS;
    }
}
