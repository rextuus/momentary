<?php

namespace App\Controller;

use App\Entity\VideoChapter;
use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Form\VideoType;
use App\Message\ConvertVideoMessage;
use App\Message\DetectVideoScenesMessage;
use App\Message\ExtractThumbnailMessage;
use App\Message\ExtractAllSceneThumbnailsMessage;
use App\Message\SplitVideoIntoFramesMessage;
use App\Message\TagScenesMessage;
use App\Message\GenerateChaptersMessage;
use App\Repository\VideoRepository;
use App\Service\WorkflowMachine;
use App\Service\VideoAnalyzer;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/video')]
final class VideoController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowMachine $workflowMachine,
        private readonly LoggerInterface $logger
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
                // Wir speichern den Pfad relativ zum Root des Projekts, ohne "public/" falls möglich,
                // aber da resolvePath nun beides kann, bleiben wir bei einem konsistenten Format.
                // Bisher wurde "public/uploads/import/" genutzt. Wir machen es expliziter.
                $video->setLocalPath('public/uploads/import/' . $video->getSourceFile());
            }

            $this->entityManager->persist($video);
            $this->entityManager->flush();

            if ($video->getLocalPath()) {
                if ($this->workflowMachine->can($video, 'start_conversion')) {
                    $this->workflowMachine->apply($video, 'start_conversion');
                    $this->messageBus->dispatch(new ConvertVideoMessage($video->getId()));
                    $this->addFlash('success', 'Lokales Video hinzugefügt und Pipeline gestartet!');
                } elseif ($this->workflowMachine->can($video, 'start_scene_detection')) {
                    $this->workflowMachine->apply($video, 'start_scene_detection');
                    $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), $video->getLocalPath()));
                    $this->addFlash('success', 'Lokales Video hinzugefügt und Analyse gestartet!');
                }
            }

            return $this->redirectToRoute('app_video_index');
        }

        return $this->render('video/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'video_delete', methods: ['POST'])]
    public function delete(Request $request, Video $video, VideoAnalyzer $videoAnalyzer): Response
    {
        if ($this->isCsrfTokenValid('delete' . $video->getId(), $request->request->get('_token'))) {
            // Erst Dateien löschen, dann Entity (bevor DB-Datensatz weg ist)
            // cleanupLocalFile löscht nun auch das Thumbnail
            $videoAnalyzer->cleanupLocalFile($video->getId());

            $this->entityManager->remove($video);
            $this->entityManager->flush();
            $this->addFlash('success', 'Video wurde erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('app_video_index');
    }


    #[Route('/{id}/extract-thumbnail', name: 'video_extract_thumbnail', methods: ['POST'])]
    public function extractThumbnail(Video $video, Request $request): RedirectResponse
    {
        $time = $request->request->get('time');
        $timeInSeconds = $time !== null ? (float) $time : 0.0;
        
        $this->logger->info(sprintf('Dispatching ExtractThumbnailMessage for video %d at %f', $video->getId(), $timeInSeconds));
        $this->messageBus->dispatch(new ExtractThumbnailMessage($video->getId(), $timeInSeconds));

        $this->addFlash('success', 'Thumbnail-Erstellung wurde in die Warteschlange eingereiht (Zeit: ' . ($timeInSeconds > 0 ? round($timeInSeconds, 2) . 's' : 'zufällig') . ').');

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    /**
     * Die fehlende Trigger-Route für die Buttons im Template
     */
    #[Route('/{id}/trigger/{step}', name: 'app_video_trigger', methods: ['GET'])]
    public function trigger(Video $video, string $step, VideoAnalyzer $videoAnalyzer, WorkflowMachine $workflowMachine): Response
    {
        try {
            // Reset error when re-triggering
            $video->setErrorMessage(null);

            match ($step) {
                'convert'  => [
                    $this->ensureStepAccessible($video, 'start_conversion', $workflowMachine),
                    $this->messageBus->dispatch(new ConvertVideoMessage($video->getId()))
                ],
                'scenes'   => [
                    $this->ensureStepAccessible($video, 'start_scene_detection', $workflowMachine),
                    $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), (string)$video->getLocalPath()))
                ],
                'thumbnails' => [
                    $this->ensureStepAccessible($video, 'start_extracting_thumbnails', $workflowMachine),
                    $this->messageBus->dispatch(new ExtractAllSceneThumbnailsMessage($video->getId()))
                ],
                'split'    => [
                    $this->ensureStepAccessible($video, 'start_splitting', $workflowMachine),
                    $this->messageBus->dispatch(new SplitVideoIntoFramesMessage($video->getId(), (string)$video->getLocalPath()))
                ],
                'refine'   => [
                    $this->ensureStepAccessible($video, 'start_refining_extraction', $workflowMachine),
                    $videoAnalyzer->refineSceneAnalysis($video)
                ],
                'reset'    => [
                    $workflowMachine->apply($video, 'reset'),
                    $videoAnalyzer->clearOldScenes($video),
                    $videoAnalyzer->clearSteps($video),
                    $this->triggerFirstStep($video, $workflowMachine)
                ],
                'tagging'   => $this->triggerTagging($video, $workflowMachine),
                'chapters'  => $this->triggerChapters($video, $workflowMachine),
                'delete'   => $videoAnalyzer->cleanupLocalFile($video->getId()),
                default    => throw new \InvalidArgumentException("Ungültiger Schritt: $step"),
            };

            $this->entityManager->flush();
            $this->addFlash('success', "Schritt '$step' wurde für '{$video->getTitle()}' manuell getriggert.");
        } catch (\Exception $e) {
            if ($workflowMachine->can($video, 'fail')) {
                $workflowMachine->apply($video, 'fail');
            }
            $video->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
            $this->addFlash('error', "Fehler beim Triggern ($step): " . $e->getMessage());
        }

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    private function triggerTagging(Video $video, WorkflowMachine $workflowMachine): void
    {
        $this->ensureStepAccessible($video, 'start_tagging', $workflowMachine);
        foreach ($video->getScenes() as $scene) {
            foreach ($scene->getTags() as $tag) {
                $scene->removeTag($tag);
            }
        }
        $this->messageBus->dispatch(new TagScenesMessage($video->getId()));
    }

    private function triggerChapters(Video $video, WorkflowMachine $workflowMachine): void
    {
        $this->ensureStepAccessible($video, 'start_chapter_generation', $workflowMachine);
        foreach ($video->getChapters() as $chapter) {
            $video->removeChapter($chapter);
            $this->entityManager->remove($chapter);
        }
        $this->messageBus->dispatch(new GenerateChaptersMessage($video->getId()));
    }

    private function triggerFirstStep(Video $video, WorkflowMachine $workflowMachine): void
    {
        if ($this->workflowMachine->can($video, 'start_conversion')) {
            $this->ensureStepAccessible($video, 'start_conversion', $workflowMachine);
            $this->messageBus->dispatch(new ConvertVideoMessage($video->getId()));
        } elseif ($this->workflowMachine->can($video, 'start_scene_detection')) {
            $this->ensureStepAccessible($video, 'start_scene_detection', $workflowMachine);
            $this->messageBus->dispatch(new DetectVideoScenesMessage($video->getId(), (string)$video->getLocalPath()));
        }
    }

    private function ensureStepAccessible(Video $video, string $transition, WorkflowMachine $workflowMachine): void
    {
        if ($workflowMachine->can($video, $transition)) {
            $workflowMachine->apply($video, $transition);
            return;
        }

        // Falls wir nicht direkt hinkönnen, schauen wir ob wir zurückspringen können
        $backTransitions = [
            'start_download' => 'back_to_pending',
            'start_conversion' => 'back_to_conversion',
            'start_scene_detection' => 'back_to_scene_detection',
            'start_extracting_thumbnails' => 'back_to_scene_detection',
            'start_splitting' => 'back_to_splitting',
            'start_refining_extraction' => 'back_to_refining_extraction',
            'start_optimization' => 'start_optimization', // Optimierung erlaubt von überall
        ];

        if (isset($backTransitions[$transition])) {
            $backTransition = $backTransitions[$transition];
            if ($workflowMachine->can($video, $backTransition)) {
                $workflowMachine->apply($video, $backTransition);
                return;
            }
        }

        throw new \RuntimeException("Der Schritt kann vom aktuellen Status ({$video->getStatus()->value}) aus nicht gestartet werden.");
    }

    /**
     * Timeline Ansicht
     */
    #[Route('/{id}/timeline', name: 'video_timeline', methods: ['GET'])]
    public function timeline(int $id): Response // Wir nehmen die ID statt des Objekts
    {
        $video = $this->videoRepository->find($id);

        if (!$video) {
            throw $this->createNotFoundException('Video nicht gefunden');
        }

        $this->denyAccessUnlessGranted('VIDEO_VIEW', $video);

        return $this->render('video/timeline.html.twig', [
            'video' => $video,
        ]);
    }

    /**
     * Detail Ansicht
     */
    #[Route('/{id}', name: 'app_video_show', methods: ['GET'])]
    #[IsGranted('VIDEO_VIEW', subject: 'video')]
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

    #[Route('/admin/video/processing-step/{id}', name: 'app_video_processing_step_show', methods: ['GET'])]
    public function showProcessingStep(VideoProcessingStep $processingStep): Response
    {
        return $this->render('video/processing_step_show.html.twig', [
            'step' => $processingStep,
        ]);
    }
    #[Route('/chapter/{id}', name: 'app_chapter_show', methods: ['GET'])]
    public function chapterShow(VideoChapter $chapter): Response
    {
        $video = $chapter->getVideo();
        $this->denyAccessUnlessGranted('VIDEO_VIEW', $video);

        $scenes = $video->getScenes()->filter(function(\App\Entity\VideoScene $scene) use ($chapter) {
            return $scene->getStartSeconds() >= $chapter->getStartSeconds() && $scene->getEndSeconds() <= $chapter->getEndSeconds();
        });

        return $this->render('video/chapter_show.html.twig', [
            'chapter' => $chapter,
            'scenes' => $scenes,
        ]);
    }
}