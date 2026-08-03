<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\UserSettings;
use App\Entity\UserGroup;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        
        // Mock Meilisearch client
        $mockClient = $this->createMock(\Meilisearch\Client::class);
        static::getContainer()->set('Meilisearch\Client', $mockClient);
        
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        
        // Clear database in correct order to avoid FK violations
        $this->entityManager->getConnection()->executeStatement('DELETE FROM video');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_group_member');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_group');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_settings');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM `user`');
    }

    private function createAdmin(): User
    {
        $user = new User();
        $user->setEmail('admin@test.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        
        $settings = new UserSettings();
        $user->setSettings($settings);
        
        $this->entityManager->persist($user);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        return $user;
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('user@test.com');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        
        $settings = new UserSettings();
        $user->setSettings($settings);
        
        $this->entityManager->persist($user);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        return $user;
    }

    public function testAdminAccess()
    {
        $admin = $this->createAdmin();
        $video = new Video();
        $video->setTitle('Private Video');
        $video->setIsPublic(false);
        $video->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($video);
        $this->entityManager->flush();
        
        $this->client->loginUser($admin);
        $this->client->request('GET', '/video/' . $video->getId());
        
        $this->assertResponseIsSuccessful();
    }

    public function testOwnerAccess()
    {
        $user = $this->createUser();
        $video = new Video();
        $video->setTitle('Owner Video');
        $video->setIsPublic(false);
        $video->setOwner($user);
        $video->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($video);
        $this->entityManager->flush();
        
        $this->client->loginUser($user);
        $this->client->request('GET', '/video/' . $video->getId());
        
        $this->assertResponseIsSuccessful();
    }
    
    public function testBlurLogicForGuest()
    {
        $video = new Video();
        $video->setTitle('Private Guest Video');
        $video->setIsPublic(false);
        $video->setThumbnailPath('http://localhost/image.jpg');
        $video->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($video);
        $this->entityManager->flush();
        
        $this->client->request('GET', '/');
        
        $this->assertSelectorExists('img[src*="bl:5"]');
    }
}
