<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Form\VideoFaceSwitchType;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\ImgproxyService;
use App\Service\Video\VideoFaceSwitchData;
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
final class VideoFaceComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public VideoFace $videoFace;

    #[LiveProp(writable: true)]
    public ?VideoFaceSwitchData $initialFormData = null;

    public bool $small = false;

    public function __construct(
        private readonly ImgproxyService $imgproxyService,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
    ) {}

    /**
     * @return FormInterface<VideoFaceSwitchData|null>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(VideoFaceSwitchType::class, $this->initialFormData);
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm();

        /** @var VideoFaceSwitchData $videoFaceSwitchData */
        $videoFaceSwitchData = $this->getForm()->getData();

        $currentPerson = $this->person();
        $currentPerson->removeVideoFace($this->videoFace);
        $currentPerson->removeDetectionFace($this->videoFace);

        $newPerson = $videoFaceSwitchData->getTarget();
        if ($newPerson === null) {
            return $this->redirectToRoute('person_detail', ['id' => $currentPerson->getId()]);
        }
        $this->videoFace->setPerson($newPerson);
        $this->videoFace->setIsVerified(true);
        $newPerson->addVideoFace($this->videoFace);

        $this->entityManager->persist($currentPerson);
        $this->entityManager->persist($newPerson);
        $this->entityManager->persist($this->videoFace);
        $this->entityManager->flush();

        return $this->redirectToRoute('person_detail', ['id' => $currentPerson->getId()]);
    }

    #[LiveAction]
    public function switchDetectionFace(): RedirectResponse
    {
        $person = $this->person();
        $person->addDetectionFace($this->videoFace);
        $this->videoFace->setDetection($person);

        $this->entityManager->persist($person);
        $this->entityManager->persist($this->videoFace);
        $this->entityManager->flush();

        return $this->redirectToRoute('person_detail', ['id' => $person->getId()]);
    }

    #[LiveAction]
    public function remove(): RedirectResponse
    {
        $person = $this->person();
        $person->removeVideoFace($this->videoFace);
        $person->removeDetectionFace($this->videoFace);
        $this->videoFace->getMedium()?->removeFace($this->videoFace);

        // Explizit löschen: über orphanRemoval am Video ginge es nur, wenn das Gesicht ein Video hat
        $this->entityManager->remove($this->videoFace);
        $this->entityManager->flush();

        // War es das letzte Gesicht einer automatisch angelegten Person, bleibt keine leere Person zurück
        if ($this->orphanedPersonCleaner->removeOrphans([(int) $person->getId()]) > 0) {
            return $this->redirectToRoute('person_index');
        }

        return $this->redirectToRoute('person_detail', ['id' => $person->getId()]);
    }

    public function getVideoUrl(): ?string
    {
        $path = $this->videoFace->getFaceImagePath();
        if ($path === null) {
            return null;
        }

        $width = $this->small ? 80 : 250;
        $height = $this->small ? 80 : 250;

        return $this->imgproxyService->generateUrl($path, $width, $height);
    }

    public function getMatchImageUrl(): ?string
    {
        $path = $this->videoFace->getMatchedBy()?->getFaceImagePath();
        if ($path === null) {
            return null;
        }

        $width = $this->small ? 80 : 250;
        $height = $this->small ? 80 : 250;

        return $this->imgproxyService->generateUrl($path, $width, $height);
    }

    public function getImageDimensions(): string
    {
        $width = 200;
        $height = 200;

        if ($this->small) {
            $width = 70;
            $height = 70;
        }

        return sprintf('style="max-width: %dpx; max-height: %dpx;"', $width, $height);
    }

    /**
     * Gesichter gehören laut Mapping immer zu einer Person (JoinColumn nullable: false).
     */
    private function person(): Person
    {
        return (
            $this->videoFace->getPerson() ?? throw new \LogicException(sprintf(
                'Face %d has no person.',
                (int) $this->videoFace->getId(),
            ))
        );
    }
}
