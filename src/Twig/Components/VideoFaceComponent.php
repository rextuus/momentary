<?php

namespace App\Twig\Components;

use App\Entity\VideoFace;
use App\Form\PersonCombineType;
use App\Form\VideoFaceSwitchType;
use App\Service\ImgproxyService;
use App\Service\Person\Data\PersonCombineData;
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
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

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
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            VideoFaceSwitchType::class,
            $this->initialFormData
        );
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm();

        /** @var VideoFaceSwitchData $videoFaceSwitchData */
        $videoFaceSwitchData = $this->getForm()->getData();

        $currentPerson = $this->videoFace->getPerson();
        $currentPerson->removeVideoFace($this->videoFace);
        $currentPerson->removeDetectionFace($this->videoFace);

        $newPerson = $videoFaceSwitchData->getTarget();
        $this->videoFace->setPerson($newPerson);
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
        $person = $this->videoFace->getPerson();
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
        $person = $this->videoFace->getPerson();
        $person->removeVideoFace($this->videoFace);
        $person->removeDetectionFace($this->videoFace);
        $this->videoFace->setPerson(null);

        $video = $this->videoFace->getVideo();
        $video->removeVideoFace($this->videoFace);

        $this->entityManager->persist($video);
        $this->entityManager->persist($person);
        $this->entityManager->persist($this->videoFace);
        $this->entityManager->flush();

        return $this->redirectToRoute('person_detail', ['id' => $person->getId()]);
    }

    public function getVideoUrl(): ?string
    {
        if (!$this->videoFace->getFaceImagePath()) {
            return null;
        }

        $width = $this->small ? 80 : 250;
        $height = $this->small ? 80 : 250;

        return $this->imgproxyService->generateUrl(
            $this->videoFace->getFaceImagePath(),
            $width,
            $height
        );
    }

    public function getMatchImageUrl(): ?string
    {
        if (!$this->videoFace->getMatchedBy()) {
            return null;
        }

        $width = $this->small ? 80 : 250;
        $height = $this->small ? 80 : 250;

        return $this->imgproxyService->generateUrl(
            $this->videoFace->getMatchedBy()->getFaceImagePath(),
            $width,
            $height
        );
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
}
