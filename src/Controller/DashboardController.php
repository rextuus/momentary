<?php

namespace App\Controller;

use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Repository\VideoRepository;
use App\Service\Video\Queue\QueueHealthChecker;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    /** Video-Status, die "in Bearbeitung" bedeuten. */
    private const VIDEO_ACTIVE_STATUSES = ['downloading', 'scene_detection', 'splitting', 'analyzing_faces', 'optimizing'];

    #[Route('/', name: 'app_dashboard')]
    public function index(PersonRepository $personRepo, VideoRepository $videoRepo, QueueHealthChecker $queueHealthChecker): Response
    {
        // Personen: eine einzige gruppierte Query statt 5 einzelner counts
        $personCounts = $this->countByStatus($personRepo->createQueryBuilder('p'), 'p');

        $total = array_sum($personCounts);
        $identified = $personCounts[PersonStatus::IDENTIFIED->value] ?? 0;
        $merged = $personCounts[PersonStatus::MERGED->value] ?? 0;
        $wasted = $personCounts[PersonStatus::WASTED->value] ?? 0;

        // Anteil der "relevanten" Personen (ohne gemergte/ausgeblendete), die identifiziert sind
        $relevant = max(0, $total - $merged - $wasted);
        $identifiedPct = $relevant > 0 ? (int) round($identified / $relevant * 100) : 0;

        // Videos: Pipeline-Zähler (bei abweichendem Property-Namen einfach leer lassen)
        $videoCounts = [];
        try {
            $videoCounts = $this->countByStatus($videoRepo->createQueryBuilder('v'), 'v');
        } catch (QueryException) {
            // Dashboard soll nie an den Pipeline-Zählern scheitern
        }

        $processing = 0;
        foreach (self::VIDEO_ACTIVE_STATUSES as $status) {
            $processing += $videoCounts[$status] ?? 0;
        }

        return $this->render('dashboard/index.html.twig', [
            'stats' => [
                'persons' => $total,
                'videos' => $videoRepo->count([]),
                'identified' => $identified,
                'identified_pct' => $identifiedPct,
                'new' => $personCounts[PersonStatus::NEW->value] ?? 0,
                'unknown' => $personCounts[PersonStatus::UNKNOWN->value] ?? 0,
                'wasted' => $wasted,
                'merged' => $merged,
                'to_reassign' => $personCounts[PersonStatus::TO_REASSIGN->value] ?? 0,
            ],
            'videoStats' => [
                'processing' => $processing,
                'pending' => $videoCounts['pending'] ?? 0,
                'failed' => $videoCounts['error'] ?? 0,
            ],
            'recent_persons' => $personRepo->findBy(['status' => PersonStatus::IDENTIFIED->value], ['id' => 'DESC'], 5),
            'recent_videos' => $videoRepo->findBy([], ['id' => 'DESC'], 5),
            'queueIsRunning' => $queueHealthChecker->isWorkerRunning(),
        ]);
    }

    /**
     * @return array<string, int> Status-Wert => Anzahl
     */
    private function countByStatus(QueryBuilder $qb, string $alias): array
    {
        $rows = $qb
            ->select(sprintf('%1$s.status AS status, COUNT(%1$s.id) AS cnt', $alias))
            ->groupBy(sprintf('%s.status', $alias))
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $key = $status instanceof \BackedEnum ? $status->value : (string) $status;
            $counts[$key] = (int) $row['cnt'];
        }

        return $counts;
    }
}