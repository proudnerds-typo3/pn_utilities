<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Service;

use Proudnerds\Utilities\Domain\ManagedUser;
use Proudnerds\Utilities\Domain\UserInput;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\SysLog\Action\Database as DatabaseAction;
use TYPO3\CMS\Core\SysLog\Error;
use TYPO3\CMS\Core\SysLog\Repository\LogEntryRepository;
use TYPO3\CMS\Core\SysLog\Type;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Lets non-admins manage backend users without lifting the core's adminOnly restriction on be_users.
 * Writes bypass the DataHandler, so every guard that matters lives here: a managed user is never an
 * admin, and only ever holds groups an admin has marked as assignable.
 */
final readonly class BackendUserManager
{
    public const ASSIGNABLE_FIELD = 'tx_pnutilities_assignable';

    private const TABLE = 'be_users';
    private const USERNAME_MAX_LENGTH = 50;
    private const REAL_NAME_MAX_LENGTH = 80;
    private const EMAIL_MAX_LENGTH = 255;

    public function __construct(
        private ConnectionPool $connectionPool,
        private PasswordHashFactory $passwordHashFactory,
        private Random $random,
        private ReferenceIndex $referenceIndex,
        private LogEntryRepository $logEntryRepository,
        private Context $context,
    ) {}

    /**
     * @return array<int, string> uid => title
     */
    public function getAssignableGroups(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('be_groups')
            ->where($queryBuilder->expr()->eq(self::ASSIGNABLE_FIELD, $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int)$row['uid']] = (string)$row['title'];
        }
        return $groups;
    }

    /**
     * Users the manager may see: not an admin, not the manager, and every group they hold is assignable.
     * Users without any group are left out, so technical and agency accounts stay out of sight.
     *
     * @return list<ManagedUser>
     */
    public function findManageable(int $managerUid): array
    {
        $assignable = $this->getAssignableGroups();
        $rows = $this->usersQuery()
            ->orderBy('username')
            ->executeQuery()
            ->fetchAllAssociative();

        $users = [];
        foreach ($rows as $row) {
            if ($this->isManageable($row, $managerUid, $assignable)) {
                $users[] = $this->toUser($row);
            }
        }
        return $users;
    }

    public function findManageableByUid(int $uid, int $managerUid): ?ManagedUser
    {
        $queryBuilder = $this->usersQuery();
        $row = $queryBuilder
            ->andWhere($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || !$this->isManageable($row, $managerUid, $this->getAssignableGroups())) {
            return null;
        }
        return $this->toUser($row);
    }

    /**
     * @return array<string, string> field => error key
     */
    public function validate(UserInput $input, ?ManagedUser $existing = null): array
    {
        $errors = [];

        if ($existing === null) {
            if ($input->username === '') {
                $errors['username'] = 'required';
            } elseif (preg_match('/\s/u', $input->username) === 1 || str_starts_with($input->username, '_')) {
                $errors['username'] = 'invalid';
            } elseif (mb_strlen($input->username) > self::USERNAME_MAX_LENGTH) {
                $errors['username'] = 'tooLong';
            } elseif ($this->isTaken('username', $input->username, null)) {
                $errors['username'] = 'taken';
            }
        }

        if (mb_strlen($input->realName) > self::REAL_NAME_MAX_LENGTH) {
            $errors['realName'] = 'tooLong';
        }

        if ($input->email === '') {
            $errors['email'] = 'required';
        } elseif (!GeneralUtility::validEmail($input->email) || mb_strlen($input->email) > self::EMAIL_MAX_LENGTH) {
            $errors['email'] = 'invalid';
        } elseif ($this->isTaken('email', $input->email, $existing?->uid)) {
            // The core password reset refuses to send a link when an address belongs to more than one user.
            $errors['email'] = 'taken';
        }

        if ($input->groupUids === []) {
            $errors['groups'] = 'required';
        } elseif (array_diff($input->groupUids, array_keys($this->getAssignableGroups())) !== []) {
            $errors['groups'] = 'invalid';
        }

        return $errors;
    }

    /**
     * The password is random and never shown: the new user sets their own through the password reset mail.
     * The core only sends that mail to users who already have a password.
     */
    public function create(UserInput $input, string $language, BackendUserAuthentication $actor): int
    {
        $now = $this->now();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'username' => $input->username,
            'password' => $this->passwordHashFactory->getDefaultHashInstance('BE')->getHashedPassword($this->random->generateRandomHexString(64)),
            'realName' => $input->realName,
            'email' => $input->email,
            'usergroup' => implode(',', $input->groupUids),
            'lang' => $language,
            'admin' => 0,
            'disable' => 0,
        ]);
        $uid = (int)$connection->lastInsertId();

        $this->referenceIndex->updateRefIndexTable(self::TABLE, $uid);
        $this->log($actor, DatabaseAction::INSERT, 'Backend user "{username}" created in user management', $uid, $input->username);
        return $uid;
    }

    public function update(ManagedUser $user, UserInput $input, BackendUserAuthentication $actor): void
    {
        $this->write($user, [
            'realName' => $input->realName,
            'email' => $input->email,
            'usergroup' => implode(',', $input->groupUids),
        ]);
        $this->referenceIndex->updateRefIndexTable(self::TABLE, $user->uid);
        $this->log($actor, DatabaseAction::UPDATE, 'Backend user "{username}" changed in user management', $user->uid, $user->username);
    }

    public function setDisabled(ManagedUser $user, bool $disabled, BackendUserAuthentication $actor): void
    {
        $this->write($user, ['disable' => (int)$disabled]);
        $message = $disabled
            ? 'Backend user "{username}" disabled in user management'
            : 'Backend user "{username}" enabled in user management';
        $this->log($actor, DatabaseAction::UPDATE, $message, $user->uid, $user->username);
    }

    public function delete(ManagedUser $user, BackendUserAuthentication $actor): void
    {
        $this->write($user, ['deleted' => 1]);
        $this->referenceIndex->updateRefIndexTable(self::TABLE, $user->uid);
        $this->log($actor, DatabaseAction::DELETE, 'Backend user "{username}" deleted in user management', $user->uid, $user->username);
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function write(ManagedUser $user, array $fields): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            $fields + ['tstamp' => $this->now()],
            ['uid' => $user->uid, 'admin' => 0],
        );
    }

    private function usersQuery(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder
            ->select('uid', 'username', 'realName', 'email', 'disable', 'admin', 'usergroup', 'lastlogin')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $assignable
     */
    private function isManageable(array $row, int $managerUid, array $assignable): bool
    {
        if ((int)$row['admin'] !== 0 || (int)$row['uid'] === $managerUid || str_starts_with((string)$row['username'], '_')) {
            return false;
        }
        $groups = GeneralUtility::intExplode(',', (string)$row['usergroup'], true);
        return $groups !== [] && array_diff($groups, array_keys($assignable)) === [];
    }

    private function isTaken(string $field, string $value, ?int $exceptUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
        if ($exceptUid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($exceptUid, Connection::PARAM_INT)));
        }
        return (int)$queryBuilder->executeQuery()->fetchOne() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toUser(array $row): ManagedUser
    {
        return new ManagedUser(
            (int)$row['uid'],
            (string)$row['username'],
            (string)$row['realName'],
            (string)$row['email'],
            (bool)$row['disable'],
            GeneralUtility::intExplode(',', (string)$row['usergroup'], true),
            (int)$row['lastlogin'],
        );
    }

    private function log(BackendUserAuthentication $actor, int $action, string $message, int $uid, string $username): void
    {
        $this->logEntryRepository->writeLogEntryForBackendUser(
            $actor,
            Type::DB,
            $action,
            Error::MESSAGE,
            $message,
            ['username' => $username],
            self::TABLE,
            $uid,
        );
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
