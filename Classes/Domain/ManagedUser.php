<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Domain;

final readonly class ManagedUser
{
    /**
     * @param list<int> $groupUids
     */
    public function __construct(
        public int $uid,
        public string $username,
        public string $realName,
        public string $email,
        public bool $disabled,
        public array $groupUids,
        public int $lastLogin,
    ) {}
}
