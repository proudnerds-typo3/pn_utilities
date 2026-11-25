<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Controller;

use Proudnerds\Utilities\Domain\StalePage;
use Proudnerds\Utilities\Service\StalePagesFinder;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
final class ToolController extends ActionController
{
    public const DEFAULT_DAYS = 182;
    public const MAX_DAYS = 36500;
    public const ITEMS_PER_PAGE = 100;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly StalePagesFinder $stalePagesFinder,
        private readonly SiteFinder $siteFinder,
        private readonly Context $context,
    ) {}

    public static function normalizeDays(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return min(self::MAX_DAYS, max(1, (int)$value));
        }
        return self::DEFAULT_DAYS;
    }

    /**
     * The paginator moves a number beyond the last page back to the last page itself.
     */
    public static function normalizePageNumber(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return max(1, (int)$value);
        }
        return 1;
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $page = $this->readSelectedPage();
        $days = self::normalizeDays($this->request->hasArgument('days') ? $this->request->getArgument('days') : null);

        $stalePages = [];
        if ($page !== null) {
            $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
            $stalePages = $this->stalePagesFinder->find((int)$page['uid'], $now - $days * 86400);
        }

        $paginator = new ArrayPaginator(
            $stalePages,
            self::normalizePageNumber($this->request->hasArgument('currentPage') ? $this->request->getArgument('currentPage') : null),
            self::ITEMS_PER_PAGE,
        );
        $rows = [];
        foreach ($paginator->getPaginatedItems() as $stalePage) {
            $rows[] = ['page' => $stalePage, 'uri' => $this->buildFrontendUri($stalePage)];
        }

        $moduleTemplate->assignMultiple([
            'page' => $page,
            'days' => $days,
            'total' => count($stalePages),
            'rows' => $rows,
            'paginator' => $paginator,
            'pagination' => new SimplePagination($paginator),
        ]);
        return $moduleTemplate->renderResponse('Tool/Index');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSelectedPage(): ?array
    {
        $body = $this->request->getParsedBody();
        $pageId = (int)($this->request->getQueryParams()['id'] ?? (is_array($body) ? ($body['id'] ?? 0) : 0));
        if ($pageId <= 0) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageId, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW));
        return is_array($page) ? $page : null;
    }

    private function buildFrontendUri(StalePage $page): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($page->defaultLanguageUid);
            return (string)$site->getRouter()->generateUri(
                $page->defaultLanguageUid,
                ['_language' => $site->getLanguageById($page->languageId)],
            );
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return null;
        }
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
