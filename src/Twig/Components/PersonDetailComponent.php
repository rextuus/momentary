<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Form\PersonCombineType;
use App\Repository\VideoFaceRepository;
use App\Service\Person\Data\PersonCombineData;
use App\Service\Person\PersonDeleter;
use App\Service\Person\PersonMerger;
use App\Service\Person\PersonNameHints;
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

    #[LiveProp(writable: ['name', 'fullName', 'age', 'gender', 'characteristics', 'relation', 'description'])]
    public Person $person;

    public function __construct(
        private VideoFaceRepository $videoFaceRepository,
        private EntityManagerInterface $entityManager,
        private PersonDeleter $personDeleter,
        private PersonMerger $personMerger,
        private PersonNameHints $nameHints,
    ) {}

    /**
     * Namen, die im Gesprochenen fallen, während die Person zu sehen ist.
     *
     * @return list<array{name: string, scenes: int}>
     */
    public function getNameHints(): array
    {
        return $this->person->hasPlaceholderName() ? $this->nameHints->forPerson($this->person) : [];
    }

    /**
     * Namenshinweis übernehmen; gibt es den Namen schon, wird mit dieser Person zusammengeführt.
     */
    #[LiveAction]
    public function applyNameHint(#[LiveArg] string $name): ?RedirectResponse
    {
        $named = $this->personMerger->name($this->person, $name);
        if ($named !== $this->person) {
            $this->addFlash('success', sprintf(
                'Mit der vorhandenen Person %s zusammengeführt.',
                $named->getDisplayName(),
            ));

            return $this->redirectToRoute('person_detail', ['id' => $named->getId()]);
        }
        $this->addFlash('success', sprintf('Heißt jetzt %s.', $name));

        return null;
    }

    /**
     * @return FormInterface<PersonCombineData|null>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(PersonCombineType::class, $this->initialFormData, ['source' => $this->person]);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->entityManager->persist($this->person);
        $this->entityManager->flush();

        $this->addFlash('success', 'Änderungen gespeichert.');
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

        $this->personMerger->merge($this->person, $targetPerson);

        $this->addFlash('success', sprintf('Mit %s zusammengeführt.', $targetPerson->getDisplayName()));
        return $this->redirectToRoute('person_index');
    }

    /**
     * Person mit allen Sichtungen endgültig löschen (z. B. Spiegelungen oder Gesichter auf Plakaten).
     */
    #[LiveAction]
    public function deletePerson(): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $name = $this->person->getName();
        $faces = $this->personDeleter->delete($this->person);

        $this->addFlash('success', sprintf('„%s“ wurde mit %d Sichtung(en) gelöscht.', $name, $faces));

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
    public function reassignFace(#[LiveArg] int $faceId): void
    {
        $face = $this->videoFaceRepository->find($faceId);

        // Sicherheitscheck: Existiert das Gesicht und gehört es aktuell zu dieser Person?
        if (!$face || $face->getPerson() !== $this->person) {
            return;
        }

        // 1. Neue temporäre Person anlegen, die nur für das Reassignment dient
        $newPerson = new Person();
        $uniqueId = substr(md5((string) hrtime(true)), 0, 8);
        $newPerson->setName('split_' . $uniqueId);
        $newPerson->setIdentified(false);
        $newPerson->setStatus(\App\Enum\PersonStatus::TO_REASSIGN);

        $this->entityManager->persist($newPerson);

        // 2. Das Gesicht der neuen Person zuweisen
        $face->setPerson($newPerson);

        // 3. Falls das Gesicht das Profilbild der alten Person war, dieses leeren
        if ($this->person->getProfileFace() === $face) {
            $this->person->setProfileFace(null);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Gesicht wurde entfernt und kann nun unter "Auftrennen" neu zugeordnet werden.');
    }
}
