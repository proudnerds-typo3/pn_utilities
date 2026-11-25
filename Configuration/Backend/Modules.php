<?php

use Proudnerds\Utilities\Configuration\Feature;
use Proudnerds\Utilities\Controller\ToolController;
use Proudnerds\Utilities\Controller\UserManagementController;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessLifetime;

$modules = [];

if (Feature::StalePages->isEnabled()) {
    $modules['web_utilitiesTools'] = [
        'parent' => 'content',
        'access' => 'admin',
        'iconIdentifier' => 'module-pn-utilities',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'labels' => 'LLL:EXT:pn_utilities/Resources/Private/Language/locallang_mod_tools.xlf',
        'extensionName' => 'PnUtilities',
        'controllerActions' => [
            ToolController::class => [
                'index',
            ],
        ],
    ];
}

if (Feature::UserManagement->isEnabled()) {
    $modules['pn_user_management'] = [
        'parent' => 'admin',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'module-user',
        'path' => '/module/pn-utilities/users',
        'labels' => 'LLL:EXT:pn_utilities/Resources/Private/Language/locallang_mod_users.xlf',
        'extensionName' => 'PnUtilities',
        'controllerActions' => [
            UserManagementController::class => [
                'list',
                'new',
                'create',
                'edit',
                'update',
                'disable',
                'enable',
                'delete',
                'sendPasswordReset',
            ],
        ],
        'routeOptions' => [
            'sudoMode' => [
                'group' => 'pnUtilitiesUserManagement',
                'lifetime' => AccessLifetime::medium,
            ],
        ],
    ];
}

return $modules;
