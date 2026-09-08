<?php

namespace Proudnerds\Utilities\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility as BackendUtilityCore;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
class ToolController extends ActionController
{
    protected $pageUid = 0;

    protected $pageInformation;

    protected $toolsAvailable = [
        'PagesNotEditedSince',
    ];

    public function __construct(protected ModuleTemplateFactory $moduleTemplateFactory) {}

    public function initializeAction(): void
    {
        $this->pageUid = $this->request->hasArgument('id') ? (int)$this->request->getQueryParams()['id'] : 0;
        $this->pageInformation = BackendUtilityCore::readPageAccess($this->pageUid, '');
        parent::initializeAction();
    }

    public function indexAction(): ResponseInterface
    {

        $tools = [];
        foreach ($this->toolsAvailable as $tool) {
            $toolClass = $this->getToolClass($tool);
            $title = $toolClass->getTitle();
            $tools[$tool] = $title;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        // Adding title, menus, buttons, etc. using $moduleTemplate ...
        $moduleTemplate->assignMultiple([
            'tools' => $tools,
            'pageUid' => $this->pageUid,
            'page' => $this->pageInformation,
        ]);
        return $moduleTemplate->renderResponse('Tool/Index');
    }

    /**
     * @param $tool
     * @return object|\Proudnerds\Utilities\Tools\BaseTool
     */
    public function getToolClass($tool)
    {
        return GeneralUtility::makeInstance('Proudnerds\\Utilities\\Tools\\' . $tool);
    }

    public function listAction(): ResponseInterface
    {
        $tool = $this->request->getArgument('tool');
        if (! in_array($tool, $this->toolsAvailable)) {
            return new ForwardResponse('index');
        }

        return new ForwardResponse(lcfirst((string)$tool));
    }

    public function pagesNotEditedSinceAction(): ResponseInterface
    {

        $tool = 'PagesNotEditedSince';
        $toolClass = $this->getToolClass($tool);

        $forReal = (bool)($this->request->hasArgument('forReal') ? $this->request->getArgument('forReal') : false);

        $toolTitle = $toolClass->getTitle();

        $result = $toolClass->executeUpdate($this->pageUid, $forReal);

        foreach ($result['errors'] as $error) {
            $this->addFlashMessage($error['text'], 'Pid ' . $error['pid'] . ' - ' . $error['tableUid'], ContextualFeedbackSeverity::ERROR);
        }

        if (count($result['errors']) == 0) {
            $this->addFlashMessage('', $forReal ? 'Update successfully performed' : 'Listing completed', ContextualFeedbackSeverity::OK);
        }

        $baseUrl = $this->getBaseUrlByPageId($this->pageUid);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        // Adding title, menus, buttons, etc. using $moduleTemplate ...
        $moduleTemplate->assignMultiple([
            'baseUrl' => $baseUrl,
            'tool' => $tool,
            'toolTitle' => $toolTitle,
            'pageUid' => $this->pageUid,
            'page' => $this->pageInformation,
            'result' => $result,
            'forReal' => $forReal,
        ]);
        return $moduleTemplate->renderResponse('Tool/PagesNotEditedSince');
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
}
