<?php

namespace App\Controller;

use App\Entity\Video;
use App\Form\VideoType;
use App\Message\DetectVideoScenesMessage;
use App\Message\DownloadVideoMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Repository\VideoRepository;
use App\Service\Video\VideoFaceMap;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/video')]
final class VideoController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private VideoRepository $videoRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Die Übersicht (vorher Index im AdminController)
     */
    #[Route('/', name: 'app_video_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('video/index.html.twig', [
            'videos' => $this->videoRepository->findAll(),
        ]);
    }

    /**
     * Neues Video hinzufügen
     */
    #[Route('/new', name: 'video_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $video = new Video();
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $video->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new DownloadVideoMessage($video->getId()));

            $this->addFlash('success', 'Video hinzugefügt und Download gestartet!');
            return $this->redirectToRoute('app_video_index');
        }

        return $this->render('video/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Die fehlende Trigger-Route für die Buttons im Template
     */
    #[Route('/{id}/trigger/{step}', name: 'app_video_trigger', methods: ['GET'])]
    public function trigger(Video $video, string $step): Response
    {
        try {
            match ($step) {
                'download' => $this->messageBus->dispatch(new DownloadVideoMessage($video->getId())),
                'scenes'   => $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), (string)$video->getLocalPath())),
                'split'    => $this->messageBus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), (string)$video->getLocalPath())),
                default    => throw new \InvalidArgumentException("Ungültiger Schritt"),
            };

            $this->addFlash('success', "Schritt '$step' wurde für '{$video->getTitle()}' manuell getriggert.");
        } catch (\Exception $e) {
            $this->addFlash('error', "Fehler beim Triggern: " . $e->getMessage());
        }

        return $this->redirectToRoute('app_video_index');
    }

    /**
     * Timeline Ansicht
     */
    #[Route('/{id}/timeline', name: 'video_timeline', methods: ['GET'])]
    public function timeline(int $id): Response // Wir nehmen die ID statt des Objekts
    {
        $video = $this->videoRepository->findFullVideo($id);

        if (!$video) {
            throw $this->createNotFoundException('Video nicht gefunden');
        }

        // Die Map brauchen wir nur noch, wenn du sie für andere Logik nutzt.
        // Für die Szenen-Darstellung filtern wir direkt im Video-Objekt.
        $map = new VideoFaceMap();
        foreach ($video->getVideoFaces() as $videoFace) {
            $map->addFace($videoFace);
        }

        return $this->render('video/timeline.html.twig', [
            'video' => $video,
            'map' => $map,
        ]);
    }
}