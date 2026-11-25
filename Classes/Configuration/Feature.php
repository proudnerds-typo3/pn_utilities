<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Configuration;

/**
 * Read from TYPO3_CONF_VARS directly: Modules.php and the TCA overrides need it before the container exists.
 * The defaults must match ext_conf_template.txt, because an installation that never saved the extension
 * configuration has no value at all.
 */
enum Feature: string
{
    case StalePages = 'stalePages';
    case UserManagement = 'userManagement';

    public function isEnabled(): bool
    {
        $value = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['pn_utilities'][$this->value] ?? null;
        if ($value === null || $value === '') {
            return $this->isEnabledByDefault();
        }
        return (bool)$value;
    }

    private function isEnabledByDefault(): bool
    {
        return match ($this) {
            self::StalePages => true,
            self::UserManagement => false,
        };
    }
}
