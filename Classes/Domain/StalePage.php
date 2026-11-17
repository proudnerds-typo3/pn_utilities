<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Domain;

final readonly class StalePage
{
    public function __construct(
        public int $uid,
        public int $pid,
        public int $defaultLanguageUid,
        public int $languageId,
        public string $title,
        public bool $hidden,
        public int $lastChanged,
    ) {}
}
