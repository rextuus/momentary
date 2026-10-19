<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoChapter;
use App\Entity\VideoProcessingStep;
use App\Entity\VideoScene;
use App\Form\VideoType;
use App\Repository\MediumRepository;
use App\Repository\VideoRepository;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Tag\TagInitializer;
use App\Service\Transcription\TranscriptionRequester;
use App\Service\Video\VideoCreationService;
use App\Service\Video\VideoFiles;
use App\Service\Video\VideoReprocessor;
use App\Service\Video\VideoStreamUrls;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/video')]
final class VideoController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TagInitializer $tagInitializer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Die Übersicht
     */
    #[Route('/', name: 'app_video_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $qb = $this->videoRepository->createQueryBuilder('v')->orderBy('v.id', \SortDirection::Descending);
        MediumRepository::restrictToVisible(
            $qb,
            'v',
            $user instanceof User ? $user : null,
            $this->isGranted('ROLE_ADMIN'),
        );

        return $this->render('video/index.html.twig', [
            'videos' => $qb->getQuery()->getResult(),
        ]);
    }

    /**
     * Neues Video hinzufügen
     */
    #[Route('/new', name: 'video_new', methods: ['GET', 'POST'])]
    public function new(Request $request, VideoCreationService $videoCreationService): Response
    {
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
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function delete(
        Request $request,
        Video $video,
        FileManager $fileManager,
        FileStorageService $fileStorageService,
        OrphanedPersonCleaner $orphanedPersonCleaner,
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $video->getId(), $request->request->getString('_token'))) {
            $videoId = $video->getId();
            $affectedPersons = $orphanedPersonCleaner->personIdsOfMedium((int) $videoId);

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

            // 5. Automatisch angelegte Personen, die nur in diesem Video vorkamen
            $orphanedPersonCleaner->removeOrphans($affectedPersons);

            $this->addFlash('success', 'Video und alle zugehörigen Daten wurden erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('app_video_index');
    }

    #[Route('/{id}/update-scenes-public', name: 'video_update_scenes_public', methods: ['POST'])]
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function updateScenesPublic(Request $request, Video $video): Response
    {
        if ($this->isCsrfTokenValid('update-scenes-public' . $video->getId(), $request->request->getString('_token'))) {
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
    #[IsGranted('VIDEO_EDIT', subject: new Expression('args["chapter"].getVideo()'))]
    public function updateChapterScenesPublic(Request $request, VideoChapter $chapter): Response
    {
        if (
            $this->isCsrfTokenValid(
                'update-chapter-scenes-public' . $chapter->getId(),
                $request->request->getString('_token'),
            )
        ) {
            $isPublic = $request->request->getBoolean('isPublic');
            $video = $chapter->getVideo() ?? throw $this->createNotFoundException();

            foreach ($video->getScenes() as $scene) {
                if (
                    $scene->getStartSeconds() >= $chapter->getStartSeconds()
                    && $scene->getEndSeconds() <= $chapter->getEndSeconds()
                ) {
                    $scene->setIsPublic($isPublic);
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Szenen des Kapitels wurden aktualisiert.');
        }

        return $this->redirectToRoute('app_chapter_show', ['id' => $chapter->getId()]);
    }

    #[Route('/{id}/extract-thumbnail', name: 'video_extract_thumbnail', methods: ['POST'])]
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function extractThumbnail(Video $video, Request $request): RedirectResponse
    {
        // TODO: Needs to be adjusted to new Analyzer
        $time = $request->request->get('time');
        $timeInSeconds = $time !== null ? (float) $time : 0.0;

        // Auf neue Step-Message umgestellt statt alter ExtractThumbnailMessage

        $this->addFlash(
            'success',
            'Thumbnail-Erstellung wurde in die Warteschlange eingereiht (Zeit: '
            . ($timeInSeconds > 0 ? round($timeInSeconds, 2) . 's' : 'zufällig')
            . ').',
        );

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    /**
     * Analyse komplett neu starten (wie bin/console app:video:reprocess).
     */
    #[Route('/{id}/reprocess', name: 'video_reprocess', methods: ['POST'])]
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function reprocess(Video $video, Request $request, VideoReprocessor $reprocessor): Response
    {
        if (!$this->isCsrfTokenValid('reprocess' . $video->getId(), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
        }

        $reason = $reprocessor->whyNot($video);
        if ($reason !== null) {
            $this->addFlash('warning', $reason);

            return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
        }

        $result = $reprocessor->reprocess([$video]);
        $this->addFlash(
            'success',
            $result['started'] > 0
                ? 'Die Analyse wurde neu gestartet.'
                : 'Das Video wurde für eine neue Analyse eingereiht und startet, sobald ein Platz in der Warteschlange frei ist.',
        );

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }

    /**
     * Ton transkribieren (optional, läuft im Hintergrund über den transcription-worker).
     */
    #[Route('/{id}/transcribe', name: 'video_transcribe', methods: ['POST'])]
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function transcribe(Video $video, Request $request, TranscriptionRequester $requester): Response
    {
        if (!$this->isCsrfTokenValid('transcribe' . $video->getId(), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');
        } elseif (($reason = $requester->whyNot($video)) !== null) {
            $this->addFlash('warning', $reason);
        } else {
            $requester->request($video);
            $this->addFlash(
                'success',
                'Die Transkription wurde eingeplant. Sie läuft im Hintergrund und kann je nach Länge eine Weile dauern.',
            );
        }

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId(), '_fragment' => 'transcript']);
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
        Request $request,
        VideoFiles $videoFiles,
        VideoStreamUrls $streamUrls,
        #[Autowire('%env(JELLYFIN_HOST)%')]
        string $jellyfinHost,
    ): Response {
        $publicJellyfinHost = str_replace('http://jellyfin:', 'http://localhost:', $jellyfinHost);
        $publicJellyfinHost = rtrim($publicJellyfinHost, '/');

        // Abspielen über die signierte Stream-URL (Web-Fassung); das Original nur für Bearbeiter auf Wunsch
        $quality = $request->query->getString('quality') === VideoFiles::QUALITY_ORIGINAL
        && $this->isGranted('VIDEO_EDIT', $video)
            ? VideoFiles::QUALITY_ORIGINAL
            : VideoFiles::QUALITY_WEB;
        $file = $videoFiles->streamFile($video, $quality);

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'jellyfin_host' => $publicJellyfinHost,
            'stream_url' => $file !== null
                ? $streamUrls->create($video, $request, quality: $file['quality'])['url']
                : null,
            'stream_quality' => $file['quality'] ?? null,
        ]);
    }

    #[Route('/admin/video/processing-step/{id}', name: 'app_video_processing_step_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function showProcessingStep(VideoProcessingStep $processingStep): Response
    {
        return $this->render('video/processing_step_show.html.twig', [
            'step' => $processingStep,
        ]);
    }

    #[Route('/chapter/{id}', name: 'app_chapter_show', methods: ['GET'])]
    public function chapterShow(VideoChapter $chapter): Response
    {
        $video = $chapter->getVideo() ?? throw $this->createNotFoundException();
        $this->entityManager->refresh($video);
        $this->denyAccessUnlessGranted('VIDEO_VIEW', $video);

        $scenes = $video
            ->getScenes()
            ->filter(
                static fn(VideoScene $scene) => (
                    $scene->getStartSeconds() < $chapter->getEndSeconds()
                    && $scene->getEndSeconds() > $chapter->getStartSeconds()
                ),
            );

        return $this->render('video/chapter_show.html.twig', [
            'chapter' => $chapter,
            'scenes' => $scenes,
        ]);
    }
}
