<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

abstract class VideoPipelineTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metaData = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metaData);
        $schemaTool->createSchema($metaData);
    }
}
