<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Person;
use App\Repository\PersonRepository;

/**
 * @implements ProviderInterface<Person>
 */
final class PersonCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PersonRepository $personRepository,
    ) {}

    /**
     * @return list<Person>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->personRepository->findActiveKnown();
    }
}
