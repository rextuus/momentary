<?php

namespace App\EventSubscriber;

use App\Entity\Video;
use App\Message\IndexVideoMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class VideoIndexerSubscriber
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        // TODO: Handle removal from MeiliSearch
        // $this->handleRemoval($args->getObject());
    }

    private function handle(object $entity): void
    {
        if (!$entity instanceof Video) {
            return;
        }

        $this->messageBus->dispatch(new IndexVideoMessage($entity->getId()));
    }
}
