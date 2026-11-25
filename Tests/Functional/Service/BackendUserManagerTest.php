<?php

declare(strict_types=1);

namespace Proudnerds\Utilities\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use Proudnerds\Utilities\Domain\ManagedUser;
use Proudnerds\Utilities\Domain\UserInput;
use Proudnerds\Utilities\Service\BackendUserManager;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendUserManagerTest extends FunctionalTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/';
    private const MANAGER = 2;

    protected array $testExtensionsToLoad = [
        'proudnerds/pn-utilities',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'pn_utilities' => [
                'userManagement' => '1',
            ],
        ],
    ];

    private BackendUserAuthentication $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(self::FIXTURES . 'be_groups.csv');
        $this->importCSVDataSet(self::FIXTURES . 'be_users.csv');
        $this->actor = $this->setUpBackendUser(self::MANAGER);
    }

    public function testAssignableGroupsSkipHiddenAndDeletedGroups(): void
    {
        self::assertSame([6 => 'Authors', 1 => 'Editors'], $this->manager()->getAssignableGroups());
    }

    public function testListsOnlyUsersWhoseGroupsAreAllAssignable(): void
    {
        self::assertSame([3, 8], $this->uids($this->manager()->findManageable(self::MANAGER)));
    }

    public function testManagerNeverSeesThemselves(): void
    {
        self::assertSame([8], $this->uids($this->manager()->findManageable(3)));
        self::assertNull($this->manager()->findManageableByUid(3, 3));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unmanageableUserProvider(): array
    {
        return [
            'admin' => [1],
            'manager with a non-assignable group' => [2],
            'one non-assignable group' => [4],
            'no groups' => [5],
            'reserved username' => [6],
            'deleted' => [7],
            'hidden group' => [9],
            'unknown' => [999],
        ];
    }

    #[DataProvider('unmanageableUserProvider')]
    public function testUnmanageableUserIsNotFound(int $uid): void
    {
        self::assertNull($this->manager()->findManageableByUid($uid, self::MANAGER));
    }

    public function testDisabledUserIsManageable(): void
    {
        $user = $this->manager()->findManageableByUid(8, self::MANAGER);

        self::assertNotNull($user);
        self::assertTrue($user->disabled);
        self::assertSame([1, 6], $user->groupUids);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function invalidInputProvider(): array
    {
        $valid = ['username' => 'new', 'realName' => 'New', 'email' => 'new@example.com', 'groups' => ['1']];
        return [
            'empty username' => [['username' => ' '] + $valid, ['username' => 'required']],
            'username with space' => [['username' => 'new user'] + $valid, ['username' => 'invalid']],
            'reserved username' => [['username' => '_new'] + $valid, ['username' => 'invalid']],
            'long username' => [['username' => str_repeat('a', 51)] + $valid, ['username' => 'tooLong']],
            'username taken' => [['username' => 'Editor'] + $valid, ['username' => 'taken']],
            'username of admin taken' => [['username' => 'admin'] + $valid, ['username' => 'taken']],
            'long name' => [['realName' => str_repeat('a', 81)] + $valid, ['realName' => 'tooLong']],
            'empty email' => [['email' => ''] + $valid, ['email' => 'required']],
            'invalid email' => [['email' => 'not-an-email'] + $valid, ['email' => 'invalid']],
            'email taken' => [['email' => 'admin@example.com'] + $valid, ['email' => 'taken']],
            'no groups' => [['groups' => []] + $valid, ['groups' => 'required']],
            'non-assignable group' => [['groups' => ['1', '3']] + $valid, ['groups' => 'invalid']],
            'hidden group' => [['groups' => ['4']] + $valid, ['groups' => 'invalid']],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $expected
     */
    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(array $data, array $expected): void
    {
        self::assertSame($expected, $this->manager()->validate(UserInput::fromArray($data)));
    }

    public function testDeletedUserDoesNotBlockUsernameOrEmail(): void
    {
        $input = UserInput::fromArray(['username' => 'removed', 'email' => 'removed@example.com', 'groups' => ['1']]);

        self::assertSame([], $this->manager()->validate($input));
    }

    public function testExistingUserMayKeepOwnEmail(): void
    {
        $user = $this->editor();
        $input = UserInput::fromArray(['realName' => 'Editor', 'email' => 'editor@example.com', 'groups' => ['1']]);

        self::assertSame([], $this->manager()->validate($input, $user));
    }

    public function testCreatesNonAdminUserWithUnknownPassword(): void
    {
        $input = UserInput::fromArray(['username' => ' New ', 'realName' => 'New', 'email' => 'new@example.com', 'groups' => ['6', '1']]);

        $uid = $this->manager()->create($input, 'nl', $this->actor);

        $row = $this->row($uid);
        self::assertSame('new', $row['username']);
        self::assertSame('6,1', $row['usergroup']);
        self::assertSame('nl', $row['lang']);
        self::assertSame(0, (int)$row['admin']);
        self::assertSame(0, (int)$row['pid']);
        self::assertStringStartsWith('$', (string)$row['password']);
        self::assertNotNull($this->manager()->findManageableByUid($uid, self::MANAGER));
    }

    public function testUpdateChangesDetailsButNotUsername(): void
    {
        $input = UserInput::fromArray(['username' => 'hijack', 'realName' => 'Renamed', 'email' => 'renamed@example.com', 'groups' => ['6']]);

        $this->manager()->update($this->editor(), $input, $this->actor);

        $row = $this->row(3);
        self::assertSame('editor', $row['username']);
        self::assertSame('Renamed', $row['realName']);
        self::assertSame('renamed@example.com', $row['email']);
        self::assertSame('6', $row['usergroup']);
    }

    public function testDisableAndEnable(): void
    {
        $this->manager()->setDisabled($this->editor(), true, $this->actor);
        self::assertSame(1, (int)$this->row(3)['disable']);

        $this->manager()->setDisabled($this->editor(), false, $this->actor);
        self::assertSame(0, (int)$this->row(3)['disable']);
    }

    public function testDeleteMarksUserDeletedAndLogsIt(): void
    {
        $this->manager()->delete($this->editor(), $this->actor);

        self::assertSame(1, (int)$this->row(3)['deleted']);
        self::assertNull($this->manager()->findManageableByUid(3, self::MANAGER));

        $log = $this->get(ConnectionPool::class)->getConnectionForTable('sys_log')
            ->select(['userid', 'tablename', 'recuid'], 'sys_log', ['tablename' => 'be_users'])
            ->fetchAssociative();
        self::assertIsArray($log);
        self::assertSame([self::MANAGER, 'be_users', 3], [(int)$log['userid'], $log['tablename'], (int)$log['recuid']]);
    }

    private function manager(): BackendUserManager
    {
        return $this->get(BackendUserManager::class);
    }

    private function editor(): ManagedUser
    {
        $user = $this->manager()->findManageableByUid(3, self::MANAGER);
        self::assertNotNull($user);
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    /**
     * @param list<ManagedUser> $users
     * @return list<int>
     */
    private function uids(array $users): array
    {
        return array_map(static fn(ManagedUser $user): int => $user->uid, $users);
    }
}
