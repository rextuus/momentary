<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\UserSettings;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SearchApiTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        
        // Mock Meilisearch client
        $mockClient = $this->createMock(\Meilisearch\Client::class);
        static::getContainer()->set(\Meilisearch\Client::class, $mockClient);
        
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        
        $this->entityManager->getConnection()->executeStatement('DELETE FROM video');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_settings');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM `user`');
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

    public function testOwnerAccessBlurStatus()
    {
        $user = $this->createUser();
        $video = new Video();
        $video->setTitle('Owner Video');
        $video->setIsPublic(false);
        $video->setOwner($user);
        $video->setThumbnailPath('http://localhost/image.jpg');
        $video->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($video);
        $this->entityManager->flush();
        
        // Mock Meilisearch
        $mockClient = static::getContainer()->get(\Meilisearch\Client::class);
        $mockIndex = $this->createMock(\Meilisearch\Endpoints\Indexes::class);
        $mockClient->method('index')->willReturn($mockIndex);
        
        $searchResult = $this->createMock(\Meilisearch\Search\SearchResult::class);
        $searchResult->method('getHits')->willReturn([
            ['id' => $video->getId()]
        ]);
        $mockIndex->method('search')->willReturn($searchResult);
        
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/search?q=Owner');
        
        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        // Assert that the video is found and blur is 0
        $this->assertNotEmpty($data);
        $this->assertEquals(0, $data[0]['blur'], 'Blur should be 0 for owner');
    }
}
