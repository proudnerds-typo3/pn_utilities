<?php

use Proudnerds\Utilities\Configuration\Feature;
use Proudnerds\Utilities\Service\BackendUserManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if (!Feature::UserManagement->isEnabled()) {
    return;
}

ExtensionManagementUtility::addTCAcolumns('be_groups', [
    BackendUserManager::ASSIGNABLE_FIELD => [
        'label' => 'LLL:EXT:pn_utilities/Resources/Private/Language/locallang_db.xlf:be_groups.tx_pnutilities_assignable',
        'description' => 'LLL:EXT:pn_utilities/Resources/Private/Language/locallang_db.xlf:be_groups.tx_pnutilities_assignable.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
        'authenticationContext' => [
            'group' => 'be.userManagement',
        ],
    ],
]);
ExtensionManagementUtility::addToAllTCAtypes('be_groups', BackendUserManager::ASSIGNABLE_FIELD, '', 'after:subgroup');
