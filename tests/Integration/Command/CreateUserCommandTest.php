<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    public function testCreatesFamilyMemberInPools(): void
    {
        $familie = UserGroupFactory::createOne(['name' => 'Familie']);
        $verwandte = UserGroupFactory::createOne(['name' => 'Verwandte']);

        $tester = $this->tester();
        $tester->execute([
            'email' => 'Anna@Example.com',
            '--pool' => ['Familie', (string) $verwandte->getId()],
        ], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $user = $this->user('anna@example.com');
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertTrue($familie->hasMember($user));
        self::assertTrue($verwandte->hasMember($user));
        self::assertStringContainsString('Pools: Familie, Verwandte', $tester->getDisplay());

        // Ohne Passwort wird eins erzeugt und einmal angezeigt
        self::assertSame(1, preg_match('/Passwort .*: (\S{4}-\S{4}-\S{4})/', $tester->getDisplay(), $match));
        self::assertTrue($this->hasher()->isPasswordValid($user, $match[1]));
    }

    public function testAsksForPasswordHiddenAndSetsRole(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['geheim-und-lang']);
        $tester->execute(['email' => 'bea@example.com', '--role' => 'editor']);

        $tester->assertCommandIsSuccessful();
        $user = $this->user('bea@example.com');
        self::assertContains('ROLE_EDITOR', $user->getRoles());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'geheim-und-lang'));
        self::assertStringNotContainsString('geheim-und-lang', $tester->getDisplay());
    }

    public function testExistingEmailFailsUnlessPasswordIsReset(): void
    {
        UserFactory::createOne(['email' => 'carl@example.com']);

        $tester = $this->tester();
        $tester->execute(['email' => 'carl@example.com', '--password' => 'neues-passwort'], ['interactive' => false]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--reset-password', $tester->getDisplay());

        $tester->execute([
            'email' => 'carl@example.com',
            '--password' => 'neues-passwort',
            '--reset-password' => true,
        ], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->hasher()->isPasswordValid($this->user('carl@example.com'), 'neues-passwort'));
    }

    public function testUnknownPoolCreatesNothing(): void
    {
        $tester = $this->tester();
        $tester->execute(['email' => 'dora@example.com', '--pool' => ['Gibtsnicht']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gibtsnicht', $tester->getDisplay());
        self::assertNull(
            $this
                ->entityManager()
                ->getRepository(User::class)
                ->findOneBy([
                    'email' => 'dora@example.com',
                ]),
        );
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
    }

    private function user(string $email): User
    {
        $this->entityManager()->clear();
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
