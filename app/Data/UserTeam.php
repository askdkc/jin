<?php

namespace App\Data;

readonly class UserTeam
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        /** @var array<string, string> */
        public array $menuNames,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
