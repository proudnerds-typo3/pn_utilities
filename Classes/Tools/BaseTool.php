<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tools;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class BaseTool
{
    // Rootpage record
    public $rootPage = [];

    public $forReal = false;

    // [pid, table:uid, details]
    public $output = [];
    // [pid, table:uid, details]
    public $errors = [];
    public $totals = [];
    public $additionalData = [];

    //Counters
    protected $pageCounter = 0;

    /**
     * @param $data array
     */
    public function writeOutput(array $data = []): void
    {
        $this->output[] = $data;
    }

    /**
     * @param $pid
     * @param $tableUid
     * @param $text
     */
    public function writeError($pid, $tableUid, $text): void
    {
        $this->errors[] = ['pid' => $pid, 'tableUid' => $tableUid, 'text' => $text];
    }

    /**
     * @param $text
     */
    public function writeTotal($text): void
    {
        $this->totals[] = $text;
    }

    /**
     * Execute the update
     *
     * Called when a wizard reports that an update is necessary
     * Call from command line:
     *       vendor/bin/typo3 upgrade:run siteAlmere23_convertTeasersToButtonListUpgradeWizard
     */
    public function executeUpdate($pageId, $forReal): array
    {
        $this->forReal = $forReal;
        $pages = self::selectQuery('pages', ['uid' => $pageId]);
        $this->rootPage = $pages[0];
        $this->processPageIncludingSubpages($this->rootPage);

        $this->writeTotal('Total no of pages found (incl. hidden, excl. deleted): ' . $this->pageCounter);
        $this->writeTotals();

        return [
            'errors' => $this->errors,
            'output' => $this->output,
            'totals' => $this->totals,
            'additionalData' => $this->additionalData,
        ];
    }

    //	public function findSitesUsingThisSitePackage($sitePackageToLookFor) {
    //		$siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
    //		$allSites = $siteFinder->getAllSites();
    //		$sitesUsingThisPackage = [];
    //		foreach ($allSites as $site) {
    //			$configuration = $site->getConfiguration();
    //			$sitePackage = $configuration['sitePackage'];
    //			if ($sitePackage === $sitePackageToLookFor) {
    //				$sitesUsingThisPackage[] = $site;
    //				$this->output->writeln('Site found: "' . $configuration['base'] . '" with template "' . $sitePackage . '", root ID: ' . $configuration['rootPageId'] . '  ');
    //			}
    //		}
    //		return $sitesUsingThisPackage;
    //	}

    //	public function performAllUpdatesOnSite($site) {
    //		$configuration = $site->getConfiguration();
    //
    //		$this->output->writeln('');
    //		$this->output->writeln('Perform updates on site "' . $configuration['base'] . '" with root page ID ' . $configuration['rootPageId'] . ':  ');
    //
    //		$pages = UpgradeTools::selectQuery('pages', ['uid' => $configuration['rootPageId']]);
    //		$this->rootPage = $pages[0];
    //		$this->processPageIncludingSubpages($this->rootPage);
    //
    //		// Write totals converted
    //		$this->output->writeln('TOTALS: ');
    //		$this->writeTotals();
    //	}

    public function writeTotals() {}

    public function processPageIncludingSubpages($page)
    {

        $this->processOnePage($page);

        $subPages = self::selectQuery('pages', ['pid' => $page['uid']], 'sorting');
        foreach ($subPages as $subPage) {
            $this->processPageIncludingSubpages($subPage);
        }
    }

    public function processOnePage($page)
    {
        $this->pageCounter += 1;
        $this->processPage($page);
    }

    public function processPage($page) {}

    public function updateQuery($tableName, $setValues, $whereClauses)
    {
        if ($this->forReal) {
            self::realUpdateQuery($tableName, $setValues, $whereClauses);
        }
    }

    public function insertQuery($tableName, $values)
    {
        if ($this->forReal) {
            self::realInsertQuery($tableName, $values);
        }
    }

    public function getBaseUrlByPageId($pageId)
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $siteConfiguration = $siteFinder->getSiteByPageId($pageId);
        $base = $siteConfiguration->getBase();
        $scheme = $base->getScheme();
        $host = $base->getHost();
        $port = $base->getPort();
        $path = $base->getPath();
        $baseUrl = $scheme . '://' . $host . ($port ? ':' . $port : '');
        return $baseUrl;
    }

    /**
     * @param string $tableName
     * @param array $whereClauses
     * @param string ...$selects
     *
     * @return array<int,array<string,mixed>>
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public static function selectQueryWithSelects($tableName, $whereClauses, string ...$selects)
    {
        $statement = self::selectStatement($tableName, $whereClauses);
        $statement = $statement->select(...$selects);
        return $statement->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param string $tableName
     * @param array $whereClauses
     * @param string|null $orderBy
     * @param bool $includeHidden
     *
     * @return array<int,array<string,mixed>>
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public static function selectQuery($tableName, $whereClauses, $orderBy = null, $includeHidden = true)
    {
        $statement = self::selectStatement($tableName, $whereClauses, $includeHidden);
        if ($orderBy) {
            foreach (explode(',', $orderBy) as $ordering) {
                $orderByParts = explode(' ', $ordering);
                $orderByField = $orderByParts[0];
                $orderByDirection = $orderByParts[1] ?? null;
                $statement->addOrderBy($orderByField, $orderByDirection);
            }
        }
        return $statement->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param string $tableName
     * @param array $whereClauses
     * @param bool $includeHidden
     *
     * @return QueryBuilder
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public static function selectStatement($tableName, $whereClauses, $includeHidden = true)
    {
        // EnableFields are automatically added in select statement
        $statement = self::startQueryWithWhereClauses($tableName, $whereClauses, $includeHidden);
        $statement = $statement
            ->select('*')
            ->from($tableName);
        return $statement;
    }

    /**
     * @param string $tableName
     * @param array $whereClauses
     * @return QueryBuilder
     */
    public static function startQueryWithWhereClauses($tableName, $whereClauses, $includeHidden = true)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if ($includeHidden === false) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        foreach ($whereClauses as $field => $equalsValue) {
            $queryBuilder->andWhere(
                is_array($equalsValue) ?
                    $queryBuilder->expr()->in(
                        $field,
                        $queryBuilder->createNamedParameter($equalsValue, Connection::PARAM_INT_ARRAY)
                    ) :
                    $queryBuilder->expr()
                        ->eq(
                            $field,
                            is_int($equalsValue) ?
                                $queryBuilder->createNamedParameter($equalsValue, Connection::PARAM_INT) :
                                $queryBuilder->createNamedParameter($equalsValue)
                        )
            );
        }
        return $queryBuilder;

    }

    public static function setDateValues($fieldValues, $dateFields = ['tstamp'])
    {
        $today = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        foreach ($dateFields as $fieldName) {
            $fieldValues[$fieldName] = $today;
        }
        return $fieldValues;
    }

    /**
     * @param string $tableName
     * @param array $values
     * @return int
     * @throws \Doctrine\DBAL\Exception
     */
    private static function realInsertQuery($tableName, $values)
    {
        $values = self::setDateValues($values, ['crdate', 'tstamp']);
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        return $queryBuilder
            ->insert($tableName)
            ->values($values)
            ->executeStatement();
    }

    /**
     * @param string $tableName
     * @param array $values
     * @param array $whereClauses
     * @return QueryBuilder
     * @throws \Doctrine\DBAL\Exception
     */
    private static function realUpdateQuery($tableName, $values, $whereClauses)
    {
        $values = self::setDateValues($values);
        $statement = self::startQueryWithWhereClauses($tableName, $whereClauses);
        foreach ($values as $field => $value) {
            $statement->set($field, $value);
        }
        $statement
            ->update($tableName)
            ->executeStatement();
        return $statement;
    }

}
