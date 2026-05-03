<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Form\PersonNameType;
use App\Service\Person\Data\PersonNameData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PersonName extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public Person $person;

    #[LiveProp(writable: true)]
    public ?PersonNameData $initialFormData = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }


    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            PersonNameType::class,
            $this->initialFormData,
            ['current' => $this->person->getName()]
        );
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm();

        /** @var PersonNameData $nameData */
        $nameData = $this->getForm()->getData();

        $this->person->setName($nameData->getName());
        $this->person->setIdentified(true);
        $this->person->setDescription(
            sprintf('Was identified as %s at %s',
                $this->person->getName(),
                (new \DateTime())->format('Y-m-d H:i:s')
            )
        );
        $this->entityManager->persist($this->person);
        $this->entityManager->flush();

        return $this->redirectToRoute('person_detail', ['id' => $this->person->getId()]);
    }
}
