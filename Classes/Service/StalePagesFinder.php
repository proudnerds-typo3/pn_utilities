<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Service;

use Proudnerds\Utilities\Domain\StalePage;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Finds standard pages whose own record and content have not changed since a given moment.
 *
 * Content from storage folders (news and the like) is not taken into account.
 */
final class StalePagesFinder
{
    private const CHUNK_SIZE = 1000;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @return list<StalePage>
     */
    public function find(int $startPageId, int $changedBefore): array
    {
        $startPage = $this->fetchStartPage($startPageId);
        if ($startPage === null) {
            return [];
        }

        $pages = $this->collectTree($startPage);
        if ($pages === []) {
            return [];
        }
        $standardUids = array_keys($pages);
        $translations = $this->fetchTranslations($standardUids);
        $lastContentChange = $this->fetchLastContentChange($standardUids);

        $stalePages = [];
        foreach ($pages as $uid => $page) {
            foreach ([$page, ...($translations[$uid] ?? [])] as $row) {
                $languageId = (int)$row['sys_language_uid'];
                $lastChanged = max(
                    (int)$row['tstamp'],
                    $lastContentChange[$uid][$languageId] ?? 0,
                    $lastContentChange[$uid][-1] ?? 0,
                );
                if ($lastChanged < $changedBefore) {
                    $stalePages[] = new StalePage(
                        uid: (int)$row['uid'],
                        pid: (int)$row['pid'],
                        defaultLanguageUid: $uid,
                        languageId: $languageId,
                        title: (string)$row['title'],
                        hidden: (bool)$row['hidden'],
                        lastChanged: $lastChanged,
                    );
                }
            }
        }
        return $stalePages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchStartPage(int $uid): ?array
    {
        $queryBuilder = $this->pagesQuery();
        $page = $queryBuilder
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($page === false) {
            return null;
        }
        if ((int)$page['sys_language_uid'] > 0 && (int)$page['l10n_parent'] > 0) {
            return $this->fetchStartPage((int)$page['l10n_parent']);
        }
        return $page;
    }

    /**
     * Walks the tree one level per query and returns the standard pages in tree order.
     *
     * @param array<string, mixed> $startPage
     * @return array<int, array<string, mixed>>
     */
    private function collectTree(array $startPage): array
    {
        $childrenByParent = [];
        $parentUids = [(int)$startPage['uid']];
        while ($parentUids !== []) {
            $nextLevel = [];
            foreach (array_chunk($parentUids, self::CHUNK_SIZE) as $chunk) {
                $queryBuilder = $this->pagesQuery();
                $rows = $queryBuilder
                    ->andWhere(
                        $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    )
                    ->orderBy('sorting')
                    ->executeQuery()
                    ->fetchAllAssociative();
                foreach ($rows as $row) {
                    $childrenByParent[(int)$row['pid']][] = $row;
                    $nextLevel[] = (int)$row['uid'];
                }
            }
            $parentUids = $nextLevel;
        }

        $pages = [];
        $this->appendInTreeOrder($startPage, $childrenByParent, $pages);
        return $pages;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, list<array<string, mixed>>> $childrenByParent
     * @param array<int, array<string, mixed>> $pages
     */
    private function appendInTreeOrder(array $page, array $childrenByParent, array &$pages): void
    {
        $uid = (int)$page['uid'];
        if ((int)$page['doktype'] === PageRepository::DOKTYPE_DEFAULT) {
            $pages[$uid] = $page;
        }
        foreach ($childrenByParent[$uid] ?? [] as $child) {
            $this->appendInTreeOrder($child, $childrenByParent, $pages);
        }
    }

    /**
     * @param list<int> $defaultLanguageUids
     * @return array<int, list<array<string, mixed>>>
     */
    private function fetchTranslations(array $defaultLanguageUids): array
    {
        $translations = [];
        foreach (array_chunk($defaultLanguageUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->pagesQuery();
            $rows = $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('sys_language_uid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $translations[(int)$row['l10n_parent']][] = $row;
            }
        }
        return $translations;
    }

    /**
     * @param list<int> $pageUids
     * @return array<int, array<int, int>> pid => sys_language_uid => newest tstamp
     */
    private function fetchLastContentChange(array $pageUids): array
    {
        $lastChange = [];
        foreach (array_chunk($pageUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->restrictedQuery('tt_content');
            $rows = $queryBuilder
                ->select('pid', 'sys_language_uid')
                ->addSelectLiteral($queryBuilder->expr()->max('tstamp', 'last_change'))
                ->from('tt_content')
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->groupBy('pid', 'sys_language_uid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $lastChange[(int)$row['pid']][(int)$row['sys_language_uid']] = (int)$row['last_change'];
            }
        }
        return $lastChange;
    }

    private function pagesQuery(): QueryBuilder
    {
        return $this->restrictedQuery('pages')
            ->select('uid', 'pid', 'title', 'doktype', 'hidden', 'tstamp', 'sys_language_uid', 'l10n_parent')
            ->from('pages');
    }

    /**
     * Hidden records count as edits; deleted records and workspace versions do not.
     */
    private function restrictedQuery(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction(0));
        return $queryBuilder;
    }
}
