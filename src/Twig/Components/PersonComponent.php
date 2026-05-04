<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Form\PersonCombineType;
use App\Service\Person\Data\PersonCombineData;
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
final class PersonComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp(writable: true)]
    public ?PersonCombineData $initialFormData = null;

    #[LiveProp]
    public Person $person;

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            PersonCombineType::class,
            $this->initialFormData,
            ['source' => $this->person]
        );
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm();
        $combineData = $this->getForm()->getData();
        $targetPerson = $combineData->getTarget();

        if (!$targetPerson || $this->person->getId() === $targetPerson->getId()) {
            return $this->redirectToRoute('person_index');
        }

        // Alle Gesichter der aktuellen Person zur Zielperson verschieben
        foreach ($this->person->getVideoFaces() as $videoFace) {
            $videoFace->setPerson($targetPerson);
            // Wir müssen nicht manuell add/remove auf den Collections aufrufen,
            // wenn die Gegenseite (VideoFace) korrekt konfiguriert ist.
            $this->entityManager->persist($videoFace);
        }

        // Falls vorhanden: DetectionFaces (Referenzbilder) übertragen
        foreach ($this->person->getDetectionFaces() as $detectionFace) {
            $detectionFace->setDetection($targetPerson);
            $this->entityManager->persist($detectionFace);
        }

        $this->entityManager->flush();

        // Jetzt die alte Person löschen
        $this->entityManager->remove($this->person);
        $this->entityManager->flush();

        $this->addFlash('success', 'Persons merged successfully.');
        return $this->redirectToRoute('person_index');
    }

    #[LiveAction]
    public function wastePerson(): RedirectResponse
    {
        $this->entityManager->remove($this->person);

        foreach ($this->person->getVideoFaces() as $videoFace) {
            $videoFace->setPerson(null);
            $this->entityManager->persist($videoFace);
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('person_index');
    }
}
