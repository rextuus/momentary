<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\Video;
use App\Entity\VideoChapter;
use App\Entity\VideoProcessingStep;
use App\Entity\VideoScene;
use App\Form\VideoType;
use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Tag\TagInitializer;
use App\Service\Video\VideoCreationService;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\Message\ThumbnailExtraction\ExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoFileService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/video')]
final class VideoController extends AbstractController
{
    public function __construct(
        private readonly VideoProcessMessageDispatcher $videoProcessMessageDispatcher,
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TagInitializer $tagInitializer,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Die Übersicht
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
    public function new(
        Request $request,
        VideoCreationService $videoCreationService
    ): Response {
        error_log('VideoController: new action called');
        $this->tagInitializer->initialize();

        $video = new Video();
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);
        error_log('VideoController: Form submitted: ' . ($form->isSubmitted() ? 'yes' : 'no'));
        if ($form->isSubmitted()) {
            error_log('VideoController: Form valid: ' . ($form->isValid() ? 'yes' : 'no'));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $sourceFilename = $form->get('sourceFile')->getData();
            if (!$sourceFilename) {
                $this->addFlash('error', 'Bitte wähle eine lokale Videodatei aus.');
                return $this->render('video/new.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            try {
                $cameraTag = $form->get('cameraTags')->getData();
                $formatTag = $form->get('formatTags')->getData();
                $autoProcess = $form->get('autoProcess')->getData();
                $this->logger->info('VideoController: autoProcess = ' . ($autoProcess ? 'true' : 'false'));
                $this->logger->info('VideoController: Calling createVideo with ' . $sourceFilename);

                $videoCreationService->createVideo($video, $sourceFilename, $cameraTag, $formatTag, $autoProcess);

                $this->addFlash('success', 'Lokales Video hinzugefügt und Pipeline gestartet!');
                return $this->redirectToRoute('app_video_index');
            } catch (\Exception $e) {
                $this->logger->error('VideoController: Fehler beim Erstellen des Videos: ' . $e->getMessage());
                $this->addFlash('error', 'Fehler beim Erstellen des Videos: ' . $e->getMessage());
                return $this->render('video/new.html.twig', [
                    'form' => $form->createView(),
                ]);
            }
        } else if ($form->isSubmitted()) {
            error_log('VideoController: Form submitted but invalid: ' . (string) $form->getErrors(true));
            $this->logger->warning('VideoController: Form is invalid: ' . (string) $form->getErrors(true));
        }

        return $this->render('video/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'video_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Video $video,
        FileManager $fileManager,
        FileStorageService $fileStorageService
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $video->getId(), $request->request->get('_token'))) {
            $videoId = $video->getId();

            // 1. Hauptdateien über den FileManager löschen
            if ($video->getSourceFile()) {
                $fileManager->deleteFile($video->getSourceFile(), true);
            }
            if ($video->getConvertedFile()) {
                $fileManager->deleteFile($video->getConvertedFile(), true);
            }
            if ($video->getThumbnailFile()) {
                $fileManager->deleteFile($video->getThumbnailFile(), true);
            }

            // 2. Video aus der Datenbank entfernen
            $this->entityManager->remove($video);
            $this->entityManager->flush();

            // 3. Physische Ordner im Storage löschen (Videodateien + Analyse-Frames)
            $fileStorageService->deleteDirectory('videos/' . $videoId);
            $fileStorageService->deleteDirectory('frames/' . $videoId);

            // 4. EntityManager-Cache komplett leeren, damit keine veralteten Identity-Map-Einträge bleiben
            $this->entityManager->clear();

            $this->addFlash('success', 'Video und alle zugehörigen Daten wurden erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('app_video_index');
    }

    #[Route('/{id}/update-scenes-public', name: 'video_update_scenes_public', methods: ['POST'])]
    public function updateScenesPublic(Request $request, Video $video): Response
    {
        if ($this->isCsrfTokenValid('update-scenes-public' . $video->getId(), $request->request->get('_token'))) {
            $isPublic = $request->request->getBoolean('isPublic');
            foreach ($video->getScenes() as $scene) {
                $scene->setIsPublic($isPublic);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Szenen wurden aktualisiert.');
        }

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    #[Route('/chapter/{id}/update-scenes-public', name: 'chapter_update_scenes_public', methods: ['POST'])]
    public function updateChapterScenesPublic(Request $request, VideoChapter $chapter): Response
    {
        if ($this->isCsrfTokenValid('update-chapter-scenes-public' . $chapter->getId(), $request->request->get('_token'))) {
            $isPublic = $request->request->getBoolean('isPublic');
            $video = $chapter->getVideo();

            foreach ($video->getScenes() as $scene) {
                if ($scene->getStartSeconds() >= $chapter->getStartSeconds() && $scene->getEndSeconds() <= $chapter->getEndSeconds()) {
                    $scene->setIsPublic($isPublic);
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Szenen des Kapitels wurden aktualisiert.');
        }

        return $this->redirectToRoute('app_chapter_show', ['id' => $chapter->getId()]);
    }

    #[Route('/{id}/extract-thumbnail', name: 'video_extract_thumbnail', methods: ['POST'])]
    public function extractThumbnail(Video $video, Request $request): RedirectResponse
    {
        // TODO: Needs to be adjusted to new Analyzer
        $time = $request->request->get('time');
        $timeInSeconds = $time !== null ? (float) $time : 0.0;

        // Auf neue Step-Message umgestellt statt alter ExtractThumbnailMessage

        $this->addFlash('success', 'Thumbnail-Erstellung wurde in die Warteschlange eingereiht (Zeit: ' . ($timeInSeconds > 0 ? round($timeInSeconds, 2) . 's' : 'zufällig') . ').');

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    #[Route('/{id}/trigger/{step}', name: 'app_video_trigger', methods: ['GET'])]
    public function trigger(Video $video): Response
    {
        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    /**
     * Timeline Ansicht
     */
    #[Route('/{id}/timeline', name: 'video_timeline', methods: ['GET'])]
    public function timeline(int $id): Response
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
        $publicJellyfinHost = str_replace('http://jellyfin:', 'http://localhost:', $jellyfinHost);
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
        $this->entityManager->refresh($video);
        $this->denyAccessUnlessGranted('VIDEO_VIEW', $video);

        $scenes = $video->getScenes()->filter(function(VideoScene $scene) use ($chapter) {
            return $scene->getStartSeconds() < $chapter->getEndSeconds() && $scene->getEndSeconds() > $chapter->getStartSeconds();
        });

        return $this->render('video/chapter_show.html.twig', [
            'chapter' => $chapter,
            'scenes' => $scenes,
        ]);
    }
}