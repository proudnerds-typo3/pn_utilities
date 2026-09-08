<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tools;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PagesNotEditedSince extends BaseTool
{
    //Counters
    protected $pagesIncludedCounter = 0;

    protected $daysSince = 182;

    /**
     * Return the speaking name of this wizard
     */
    public function getTitle(): string
    {
        return 'Show pages not edited since ' . $this->daysSince . ' days';
    }

    public function writeTotals(): void
    {
        $this->writeTotal('Pages not edited since: ' . $this->pagesIncludedCounter);
    }

    public function executeUpdate($pageId, $forReal): array
    {
        $this->additionalData['baseUrl'] = $this->getBaseUrlByPageId($pageId);
        return parent::executeUpdate($pageId, $forReal);
    }

    public function processPageIncludingSubpages($page)
    {
        parent::processPageIncludingSubpages($page);
        $this->additionalData['pagesNotEditedSince'] = $this->pagesIncludedCounter;
    }

    public function processOnePage($page)
    {
        $doktypesIncluded = [1];
        if (in_array((int)($page['doktype']), $doktypesIncluded)) {
            $this->pageCounter += 1;
            $this->processPage($page);
        }
    }

    /**
     * @param array $page
     */
    public function processPage($page)
    {
        $pageId = $page['uid'];
        $table = 'pages';

        $limitTimestamp = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp') - ($this->daysSince * 24 * 3600);
        $lastChanged = max($page['SYS_LASTCHANGED'], $page['tstamp']);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        // Fetch all selected pages via FE to update SYS_LASTCHANGED field
        if ($lastChanged <= $limitTimestamp && $this->forReal) {
            @file_get_contents($this->additionalData['baseUrl'] . $page['slug']);
            $page = self::selectQuery('pages', ['uid' => $pageId])[0];
            $lastChanged = max($page['SYS_LASTCHANGED'], $page['tstamp']);
        }

        if ($lastChanged <= $limitTimestamp) {
            $this->writeOutput(
                [
                    'tableName' => $table,
                    'icon' => $iconFactory->getIconForRecord($table, $page, IconSize::SMALL)->render(),
                    'record' => $page,
                    'lastChanged' => $lastChanged,
                ],
            );
            $this->pagesIncludedCounter += 1;
        }
    }

}
