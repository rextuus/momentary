<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\PersonRepository;

final class PersonCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PersonRepository $personRepository,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->personRepository->findActiveKnown();
    }
}
