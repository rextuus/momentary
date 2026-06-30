<?php

namespace App\Controller;

use App\Entity\Video;
use App\Form\VideoType;
use App\Message\DetectVideoScenesMessage;
use App\Message\DownloadVideoMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Repository\VideoRepository;
use App\Service\Video\VideoFaceMap;
use App\Service\VideoAnalyzer;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video')]
final class VideoController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private VideoRepository $videoRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/public/uploads/import')]
        private string $importDir
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

            if ($video->getSourceFile()) {
                $video->setLocalPath('public/uploads/import/' . $video->getSourceFile());
            }

            $this->entityManager->persist($video);
            $this->entityManager->flush();

            if ($video->getYoutubeUrl()) {
                $this->messageBus->dispatch(new DownloadVideoMessage($video->getId()));
                $this->addFlash('success', 'Video hinzugefügt und Download gestartet!');
            } elseif ($video->getLocalPath()) {
                $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), $video->getLocalPath()));
                $this->addFlash('success', 'Lokales Video hinzugefügt und Analyse gestartet!');
            }

            return $this->redirectToRoute('app_video_index');
        }

        return $this->render('video/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/set-youtube-url', name: 'video_set_youtube_url', methods: ['POST'])]
    public function setYoutubeUrl(Video $video, Request $request, VideoAnalyzer $videoAnalyzer): RedirectResponse
    {
        $url = $request->request->get('youtube_url');
        if ($url) {
            $video->setYoutubeUrl($url);
            $this->entityManager->flush();

            // Trigger cleanup if completed
            if ($video->getStatus() === VideoStatus::COMPLETED) {
                $videoAnalyzer->cleanupLocalFile($video->getId());
            }

            $this->addFlash('success', 'YouTube-Link wurde gespeichert.');
        }

        return $this->redirectToRoute('app_video_index');
    }

    /**
     * Die fehlende Trigger-Route für die Buttons im Template
     */
    #[Route('/{id}/trigger/{step}', name: 'app_video_trigger', methods: ['GET'])]
    public function trigger(Video $video, string $step, VideoAnalyzer $videoAnalyzer): Response
    {
        try {
            // Reset error when re-triggering
            $video->setErrorMessage(null);

            match ($step) {
                'scenes'   => [
                    $video->setStatus(VideoStatus::SCENE_DETECTION),
                    $video->setConvertedAt($video->getConvertedAt() ?? new \DateTimeImmutable()), // Mark conversion done if skipping to here
                    $video->setScenesDetectedAt(null),
                    $video->setFramesExtractedAt(null),
                    $video->setFacesAnalyzedAt(null),
                    $video->setRefinedAt(null),
                    $video->setCompletedAt(null),
                    (function() use ($video) { $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), (string)$video->getLocalPath())); })()
                ],
                'split'    => [
                    $video->setStatus(VideoStatus::SPLITTING),
                    $video->setFramesExtractedAt(null),
                    $video->setFacesAnalyzedAt(null),
                    $video->setRefinedAt(null),
                    $video->setCompletedAt(null),
                    (function() use ($video) { $this->messageBus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), (string)$video->getLocalPath())); })()
                ],
                'refine'   => [
                    $video->setStatus(VideoStatus::REFINING_EXTRACTION),
                    $video->setRefinedAt(null),
                    $video->setRefiningExtractionFinishedAt(null),
                    $video->setRefiningAnalysisFinishedAt(null),
                    $video->setCompletedAt(null),
                    $videoAnalyzer->refineSceneAnalysis($video)
                ],
                'reset'    => [
                    $video->setStatus(VideoStatus::PENDING),
                    $video->setDownloadedAt(null),
                    $video->setConvertedAt(null),
                    $video->setScenesDetectedAt(null),
                    $video->setFramesExtractedAt(null),
                    $video->setFacesAnalyzedAt(null),
                    $video->setRefiningExtractionFinishedAt(null),
                    $video->setRefiningAnalysisFinishedAt(null),
                    $video->setRefinedAt(null),
                    $video->setCompletedAt(null),
                    $video->setDownloadDuration(null),
                    $video->setConversionDuration(null),
                    $video->setSceneDetectionDuration(null),
                    $video->setFrameExtractionDuration(null),
                    $video->setFaceAnalysisDuration(null),
                    $video->setRefiningExtractionDuration(null),
                    $video->setRefiningAnalysisDuration(null),
                    $video->setRefinementDuration(null),
                    (function() use ($videoAnalyzer, $video) { $videoAnalyzer->clearOldScenes($video); })()
                ],
                'delete'   => (function() use ($videoAnalyzer, $video) { $videoAnalyzer->cleanupLocalFile($video->getId()); })(),
                default    => throw new \InvalidArgumentException("Ungültiger Schritt"),
            };

            $this->entityManager->flush();
            $this->addFlash('success', "Schritt '$step' wurde für '{$video->getTitle()}' manuell getriggert.");
        } catch (\Exception $e) {
            $video->setStatus(VideoStatus::ERROR);
            $video->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
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

    /**
     * Detail Ansicht
     */
    #[Route('/{id}', name: 'app_video_show', methods: ['GET'])]
    public function show(
        Video $video,
        #[Autowire('%env(JELLYFIN_HOST)%')] string $jellyfinHost,
        #[Autowire('%env(JELLYFIN_API_KEY)%')] string $jellyfinApiKey
    ): Response {
        // Für den Browser müssen wir ggf. den Host anpassen, wenn er intern anders heißt als extern
        $publicJellyfinHost = str_replace('http://jellyfin:', 'http://localhost:', $jellyfinHost);

        // Optional: Ensure the host has no trailing slash to avoid double slashes in URLs
        $publicJellyfinHost = rtrim($publicJellyfinHost, '/');

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'jellyfin_host' => $publicJellyfinHost,
            'jellyfin_api_key' => $jellyfinApiKey,
        ]);
    }
}