<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Form\PersonCombineType;
use App\Repository\VideoFaceRepository;
use App\Service\Person\Data\PersonCombineData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class PersonDetailComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp(writable: true)]
    public ?PersonCombineData $initialFormData = null;

    #[LiveProp]
    public Person $person;

    public function __construct(
        private VideoFaceRepository $videoFaceRepository,
        private EntityManagerInterface $entityManager
    ) {}

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            PersonCombineType::class,
            $this->initialFormData,
            ['source' => $this->person]
        );
    }

    #[LiveAction]
    public function merge(): RedirectResponse
    {
        $this->submitForm();
        $combineData = $this->getForm()->getData();
        $targetPerson = $combineData->getTarget();

        if (!$targetPerson || $this->person->getId() === $targetPerson->getId()) {
            return $this->redirectToRoute('person_detail', ['id' => $this->person->getId()]);
        }

        // Alle Gesichter der aktuellen Person zur Zielperson verschieben
        foreach ($this->person->getVideoFaces() as $videoFace) {
            $videoFace->setPerson($targetPerson);
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

        $this->addFlash('success', sprintf('Merged into %s', $targetPerson->getName()));
        return $this->redirectToRoute('person_index');
    }

    #[LiveAction]
    public function setAsProfile(#[LiveArg] int $faceId): void
    {
        $face = $this->videoFaceRepository->find($faceId);

        // Sicherheitscheck: Gehört das Gesicht zur Person?
        if ($face && $face->getPerson() === $this->person) {
            $this->person->setProfileFace($face);
            $this->entityManager->flush();

            $this->addFlash('success', 'Profilbild wurde aktualisiert.');
        }
    }

    #[LiveAction]
    public function splitToNewPerson(#[LiveArg] int $faceId): void
    {
        $face = $this->videoFaceRepository->find($faceId);

        // Sicherheitscheck: Existiert das Gesicht und gehört es aktuell zu dieser Person?
        if (!$face || $face->getPerson() !== $this->person) {
            return;
        }

        // 1. Neue unbekannte Person anlegen
        $newPerson = new Person();
        $uniqueId = substr(md5((string)hrtime(true)), 0, 8);
        $newPerson->setName('unknown_' . $uniqueId);
        $newPerson->setIdentified(false);
        $newPerson->setStatus(\App\Enum\PersonStatus::NEW);

        $this->entityManager->persist($newPerson);

        // 2. Das Gesicht der neuen Person zuweisen
        $face->setPerson($newPerson);

        // 3. Falls das Gesicht das Profilbild der alten Person war, dieses leeren
        if ($this->person->getProfileFace() === $face) {
            $this->person->setProfileFace(null);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Gesicht wurde erfolgreich in eine neue Person ausgelagert.');
    }
}