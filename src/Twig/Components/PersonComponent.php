<?php

namespace App\Twig\Components;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PersonComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Person $person;

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[LiveAction]
    public function wastePerson(): RedirectResponse
    {
        $this->entityManager->remove($this->person);
        $this->entityManager->flush();

        return $this->redirectToRoute('person_index');
    }
}
