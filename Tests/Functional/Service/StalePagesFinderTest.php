<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tests\Functional\Service;

use Proudnerds\Utilities\Domain\StalePage;
use Proudnerds\Utilities\Service\StalePagesFinder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StalePagesFinderTest extends FunctionalTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/';
    private const OLD = 1600000000;
    private const CUTOFF = 1700000000;
    private const RECENT = 1750000000;

    protected array $testExtensionsToLoad = [
        'proudnerds/pn-utilities',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(self::FIXTURES . 'pages.csv');
        $this->importCSVDataSet(self::FIXTURES . 'tt_content.csv');
    }

    public function testFindsStalePagesInTree(): void
    {
        self::assertSame(
            [[1, 0], [2, 0], [10, 1], [4, 0], [7, 0]],
            $this->uidsAndLanguages($this->finder()->find(1, self::CUTOFF)),
        );
    }

    public function testHiddenPageIsMarked(): void
    {
        $hidden = array_filter(
            $this->finder()->find(1, self::CUTOFF),
            static fn(StalePage $page): bool => $page->hidden,
        );

        self::assertSame([[4, 0]], $this->uidsAndLanguages(array_values($hidden)));
    }

    public function testDescendsThroughFolders(): void
    {
        $uids = array_column($this->uidsAndLanguages($this->finder()->find(1, self::CUTOFF)), 0);

        self::assertContains(7, $uids);
        self::assertNotContains(6, $uids);
    }

    public function testExcludesNonStandardDoktypes(): void
    {
        $uids = array_column($this->uidsAndLanguages($this->finder()->find(1, PHP_INT_MAX)), 0);

        self::assertNotContains(14, $uids);
    }

    public function testLastChangedIsMaxOfPageAndContent(): void
    {
        $byUid = [];
        foreach ($this->finder()->find(1, 1800000000) as $page) {
            $byUid[$page->uid] = $page;
        }

        self::assertSame(self::RECENT, $byUid[3]->lastChanged);
        self::assertSame(self::OLD, $byUid[2]->lastChanged);
        self::assertSame(self::RECENT, $byUid[9]->lastChanged);
        self::assertSame(2, $byUid[9]->defaultLanguageUid);
    }

    public function testTranslatedStartPageResolvesToDefaultLanguage(): void
    {
        self::assertEquals(
            $this->finder()->find(2, self::CUTOFF),
            $this->finder()->find(9, self::CUTOFF),
        );
    }

    public function testUnknownStartPageReturnsEmptyList(): void
    {
        self::assertSame([], $this->finder()->find(999, self::CUTOFF));
    }

    public function testDeletedStartPageReturnsEmptyList(): void
    {
        self::assertSame([], $this->finder()->find(5, self::CUTOFF));
    }

    public function testHandlesMoreThanChunkSizePages(): void
    {
        $rows = [];
        for ($uid = 1000; $uid < 2200; $uid++) {
            $rows[] = [$uid, 7, 'Generated ' . $uid, 1, $uid, self::OLD];
        }
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->bulkInsert(
            'pages',
            $rows,
            ['uid', 'pid', 'title', 'doktype', 'sorting', 'tstamp'],
        );

        self::assertCount(1201, $this->finder()->find(7, self::CUTOFF));
    }

    private function finder(): StalePagesFinder
    {
        return new StalePagesFinder($this->get(ConnectionPool::class));
    }

    /**
     * @param list<StalePage> $pages
     * @return list<array{int, int}>
     */
    private function uidsAndLanguages(array $pages): array
    {
        return array_map(static fn(StalePage $page): array => [$page->uid, $page->languageId], $pages);
    }
}
