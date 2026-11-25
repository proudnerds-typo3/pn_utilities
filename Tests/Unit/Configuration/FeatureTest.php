<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Proudnerds\Utilities\Configuration\Feature;

final class FeatureTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $backup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'] = $this->backup;
        parent::tearDown();
    }

    public function testDefaultsMatchExtConfTemplate(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['pn_utilities']);

        $template = (string)file_get_contents(__DIR__ . '/../../../ext_conf_template.txt');
        foreach (Feature::cases() as $feature) {
            self::assertMatchesRegularExpression('/^' . $feature->value . ' = ' . (int)$feature->isEnabled() . '$/m', $template);
        }
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function valueProvider(): array
    {
        return [
            'saved as on' => ['1', true],
            'saved as off' => ['0', false],
            'empty falls back to default' => ['', false],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testReadsSavedValue(mixed $value, bool $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['pn_utilities']['userManagement'] = $value;

        self::assertSame($expected, Feature::UserManagement->isEnabled());
    }

    public function testStalePagesCanBeSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['pn_utilities']['stalePages'] = '0';

        self::assertFalse(Feature::StalePages->isEnabled());
    }
}
