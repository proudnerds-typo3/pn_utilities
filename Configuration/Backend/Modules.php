<?php

use Proudnerds\Utilities\Controller\ToolController;

return [
    'web_utilitiesTools' => [
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
    ],
];
