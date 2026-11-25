<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Domain;

final readonly class UserInput
{
    /**
     * @param list<int> $groupUids
     */
    public function __construct(
        public string $username,
        public string $realName,
        public string $email,
        public array $groupUids,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        $groupUids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int)$uid : 0, $groups),
            static fn(int $uid): bool => $uid > 0,
        )));

        return new self(
            mb_strtolower(trim(self::string($data['username'] ?? ''))),
            trim(self::string($data['realName'] ?? '')),
            trim(self::string($data['email'] ?? '')),
            $groupUids,
        );
    }

    public static function fromUser(ManagedUser $user): self
    {
        return new self($user->username, $user->realName, $user->email, $user->groupUids);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
