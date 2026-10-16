<?php

declare(strict_types=1);

namespace App\Debug;

use App\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use ReflectionProperty;

#[AsDoctrineListener(event: Events::prePersist)]
class FileWithoutPathListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof File) {
            return;
        }

        $property = new ReflectionProperty(File::class, 'relativePath');
        if (!$property->isInitialized($entity)) {
            throw new \LogicException(
                'File without relativePath is being persisted: ' . get_debug_type($entity)
            );
        }
    }
}