<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Proudnerds\Utilities\Controller\ToolController;

final class ToolControllerTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function daysProvider(): array
    {
        return [
            'empty string' => ['', 182],
            'null' => [null, 182],
            'not a number' => ['abc', 182],
            'zero' => ['0', 1],
            'negative' => [-5, 1],
            'numeric string' => ['30', 30],
            'integer' => [30, 30],
            'beyond the maximum' => ['99999999999999999999', 36500],
        ];
    }

    #[DataProvider('daysProvider')]
    public function testNormalizeDays(mixed $input, int $expected): void
    {
        self::assertSame($expected, ToolController::normalizeDays($input));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageNumberProvider(): array
    {
        return [
            'null' => [null, 1],
            'not a number' => ['abc', 1],
            'zero' => ['0', 1],
            'negative' => [-3, 1],
            'numeric string' => ['4', 4],
            'integer' => [4, 4],
            'array' => [['2'], 1],
        ];
    }

    #[DataProvider('pageNumberProvider')]
    public function testNormalizePageNumber(mixed $input, int $expected): void
    {
        self::assertSame($expected, ToolController::normalizePageNumber($input));
    }
}
