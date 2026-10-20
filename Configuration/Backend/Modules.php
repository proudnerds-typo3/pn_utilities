<?php

use Proudnerds\Utilities\Controller\ToolController;

return [
    'web_utilitiesTools' => [
        'parent' => 'web',
        'access' => 'admin',
        'iconIdentifier' => 'module-pn-utilities',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'labels' => 'LLL:EXT:pn_utilities/Resources/Private/Language/locallang_mod_tools.xlf',
        'extensionName' => 'utilities',
        'controllerActions' => [
            ToolController::class => [
                'index',
                'list',
                'pagesNotEditedSince',
            ],
        ],
    ],
];
