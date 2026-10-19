<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Person;
use App\Entity\User;
use App\Repository\PersonRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Person>
 */
final class PersonCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PersonRepository $personRepository,
        private Security $security,
    ) {}

    /**
     * @return list<Person>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        // Nur Personen aus Medien, die der Benutzer sehen darf (Pools)
        return $this->personRepository->findActiveKnown(
            $user instanceof User ? $user : null,
            $this->security->isGranted('ROLE_ADMIN'),
        );
    }
}
