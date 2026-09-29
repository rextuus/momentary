<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Video\Queue\QueueHealthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;

#[Route('/admin/queue', name: 'app_admin_queue', methods: ['GET'])]
class QueueController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly QueueHealthChecker $queueHealthChecker
    ) {}

    public function __invoke(): Response
    {
        $statuses = [
            VideoStatus::QUEUED,
            VideoStatus::CONVERTING,
            VideoStatus::SCENE_DETECTION,
            VideoStatus::EXTRACTING_THUMBNAILS,
            VideoStatus::VIDEO_SPLITTING,
            VideoStatus::ANALYZING_FACES_INITIAL,
            VideoStatus::REFINING_EXTRACTION,
            VideoStatus::REFINING_SPLITTING,
            VideoStatus::ANALYZING_FACES_REFINEMENT,
            VideoStatus::MERGING_SCENES,
            VideoStatus::TAGGING_SCENES,
            VideoStatus::CHAPTER_GENERATION,
            VideoStatus::NAMING,
            VideoStatus::EXPORTING_JELLYFIN,
        ];

        $videos = $this->videoRepository->findBy(['status' => $statuses], ['createdAt' => 'ASC']);

        return $this->render('admin/queue/index.html.twig', [
            'videos' => $videos,
            'isWorkerRunning' => $this->queueHealthChecker->isWorkerRunning(),
        ]);
    }
}
