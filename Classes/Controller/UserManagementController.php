<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Controller;

use Proudnerds\Utilities\Domain\ManagedUser;
use Proudnerds\Utilities\Domain\UserInput;
use Proudnerds\Utilities\Service\BackendUserManager;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Authentication\PasswordReset;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class UserManagementController extends ActionController
{
    use AllowedMethodsTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUserManager $userManager,
        private readonly PasswordReset $passwordReset,
        private readonly PageRenderer $pageRenderer,
        private readonly Context $context,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');
        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'users' => $this->userManager->findManageable($this->managerUid()),
            'groups' => $this->userManager->getAssignableGroups(),
            'passwordResetEnabled' => $this->isPasswordResetEnabled(),
        ]);
        return $moduleTemplate->renderResponse('UserManagement/List');
    }

    public function newAction(): ResponseInterface
    {
        return $this->renderForm(new UserInput('', '', '', []), null, []);
    }

    protected function initializeCreateAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function createAction(): ResponseInterface
    {
        $input = $this->readInput();
        $errors = $this->userManager->validate($input);
        if ($errors !== []) {
            return $this->renderForm($input, null, $errors);
        }

        $language = (string)($this->getBackendUser()->user['lang'] ?? '') ?: 'en';
        $uid = $this->userManager->create($input, $language, $this->getBackendUser());
        $this->addMessage('created', [$input->username]);
        $this->sendPasswordReset($uid, $input->email);
        return $this->redirect('list');
    }

    public function editAction(int $uid): ResponseInterface
    {
        $user = $this->findUserOrNull($uid);
        if ($user === null) {
            return $this->notFound();
        }
        return $this->renderForm(UserInput::fromUser($user), $user, []);
    }

    protected function initializeUpdateAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function updateAction(int $uid): ResponseInterface
    {
        $user = $this->findUserOrNull($uid);
        if ($user === null) {
            return $this->notFound();
        }
        $input = $this->readInput();
        $errors = $this->userManager->validate($input, $user);
        if ($errors !== []) {
            return $this->renderForm($input, $user, $errors);
        }

        $this->userManager->update($user, $input, $this->getBackendUser());
        $this->addMessage('updated', [$user->username]);
        return $this->redirect('list');
    }

    protected function initializeDisableAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function disableAction(int $uid): ResponseInterface
    {
        return $this->toggle($uid, true);
    }

    protected function initializeEnableAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function enableAction(int $uid): ResponseInterface
    {
        return $this->toggle($uid, false);
    }

    protected function initializeDeleteAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAction(int $uid): ResponseInterface
    {
        $user = $this->findUserOrNull($uid);
        if ($user === null) {
            return $this->notFound();
        }
        $this->userManager->delete($user, $this->getBackendUser());
        $this->addMessage('deleted', [$user->username]);
        return $this->redirect('list');
    }

    protected function initializeSendPasswordResetAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function sendPasswordResetAction(int $uid): ResponseInterface
    {
        $user = $this->findUserOrNull($uid);
        if ($user === null) {
            return $this->notFound();
        }
        $this->sendPasswordReset($user->uid, $user->email);
        return $this->redirect('list');
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderForm(UserInput $input, ?ManagedUser $user, array $errors): ResponseInterface
    {
        $groupOptions = [];
        foreach ($this->userManager->getAssignableGroups() as $uid => $title) {
            $groupOptions[] = ['uid' => $uid, 'title' => $title, 'selected' => in_array($uid, $input->groupUids, true)];
        }

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'input' => $input,
            'user' => $user,
            'formArguments' => $user !== null ? ['uid' => $user->uid] : [],
            'errors' => $errors,
            'groupOptions' => $groupOptions,
        ]);
        return $moduleTemplate->renderResponse('UserManagement/Form');
    }

    private function toggle(int $uid, bool $disable): ResponseInterface
    {
        $user = $this->findUserOrNull($uid);
        if ($user === null) {
            return $this->notFound();
        }
        $this->userManager->setDisabled($user, $disable, $this->getBackendUser());
        $this->addMessage($disable ? 'disabled' : 'enabled', [$user->username]);
        return $this->redirect('list');
    }

    /**
     * The core only sends the mail to enabled users with a unique address, and silently drops requests
     * beyond its rate limit, so the message says the mail was requested, not that it arrived.
     */
    private function sendPasswordReset(int $uid, string $email): void
    {
        if (!$this->isPasswordResetEnabled() || !$this->passwordReset->isEnabledForUser($uid)) {
            $this->addMessage('resetUnavailable', [$email], ContextualFeedbackSeverity::WARNING);
            return;
        }
        $this->passwordReset->initiateReset($this->request, $this->context, $email);
        $this->addMessage('resetSent', [$email]);
    }

    private function isPasswordResetEnabled(): bool
    {
        return (bool)($GLOBALS['TYPO3_CONF_VARS']['BE']['passwordReset'] ?? false);
    }

    private function findUserOrNull(int $uid): ?ManagedUser
    {
        return $this->userManager->findManageableByUid($uid, $this->managerUid());
    }

    private function notFound(): ResponseInterface
    {
        $this->addMessage('notFound', [], ContextualFeedbackSeverity::ERROR);
        return $this->redirect('list');
    }

    private function readInput(): UserInput
    {
        $data = $this->request->hasArgument('user') ? $this->request->getArgument('user') : [];
        return UserInput::fromArray(is_array($data) ? $data : []);
    }

    private function createModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $moduleTemplate->setTitle($this->translate('users.title'));
        return $moduleTemplate;
    }

    /**
     * @param list<string> $arguments
     */
    private function addMessage(string $key, array $arguments, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): void
    {
        $this->addFlashMessage($this->translate('users.message.' . $key, $arguments), '', $severity);
    }

    /**
     * @param list<string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate(
            'LLL:EXT:pn_utilities/Resources/Private/Language/locallang.xlf:' . $key,
            null,
            $arguments,
        ) ?? $key;
    }

    private function managerUid(): int
    {
        return (int)$this->getBackendUser()->getUserId();
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
