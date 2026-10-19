<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\MediumRepository;
use App\Repository\PersonRepository;
use App\Twig\Extension\ImgproxyExtension;
use App\Twig\Extension\MediaExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Karte aller Medien mit Aufnahmeort.
 */
final class MapController extends AbstractController
{
    private const int MAX_POINTS = 5000;

    #[Route('/map', name: 'app_map', methods: ['GET'])]
    public function index(Request $request, PersonRepository $personRepository): Response
    {
        $query = array_filter([
            'person' => (int) $request->query->getString('person') ?: null,
            'type' => \in_array($request->query->getString('type'), ['video', 'photo'], true)
                ? $request->query->getString('type')
                : null,
        ]);

        return $this->render('map/index.html.twig', [
            'query' => $query,
            'persons' => $personRepository->findBy(['status' => PersonStatus::IDENTIFIED], ['name' => 'ASC']),
        ]);
    }

    /**
     * Punkte für die Karte. Filter: event, medium, person, type.
     */
    #[Route('/map/points', name: 'app_map_points', methods: ['GET'])]
    public function points(
        Request $request,
        EntityManagerInterface $entityManager,
        ImgproxyExtension $imgproxy,
        MediaExtension $media,
    ): JsonResponse {
        $qb = $entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(Medium::class, 'm')
            ->where('m.latitude IS NOT NULL AND m.longitude IS NOT NULL')
            ->setMaxResults(self::MAX_POINTS);
        $user = $this->getUser();
        MediumRepository::restrictToVisible(
            $qb,
            'm',
            $user instanceof User ? $user : null,
            $this->isGranted('ROLE_ADMIN'),
        );

        if ((int) $request->query->getString('event') > 0) {
            $qb
                ->join('m.events', 'e')
                ->andWhere('e.id = :event')
                ->setParameter('event', (int) $request->query->getString('event'));
        }
        if ((int) $request->query->getString('medium') > 0) {
            $qb->andWhere('m.id = :medium')->setParameter('medium', (int) $request->query->getString('medium'));
        }
        if ((int) $request->query->getString('person') > 0) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s mf WHERE mf.medium = m AND mf.person = :person)',
                VideoFace::class,
            ))->setParameter('person', (int) $request->query->getString('person'));
        }
        match ($request->query->getString('type')) {
            'video' => $qb->andWhere(sprintf('m INSTANCE OF %s', Video::class)),
            'photo' => $qb->andWhere(sprintf('m INSTANCE OF %s', Photo::class)),
            default => null,
        };

        $points = [];
        /** @var Medium $medium */
        foreach ($qb->getQuery()->getResult() as $medium) {
            $preview = $medium->getPreviewFile();
            $points[] = [
                'id' => $medium->getId(),
                'lat' => $medium->getLatitude(),
                'lon' => $medium->getLongitude(),
                'type' => $medium->getType(),
                'title' => $medium->getTitle(),
                'place' => $medium->getPlaceName(),
                'date' => $medium
                    ->getDate()
                    ?->setTimezone(new \DateTimeZone('Europe/Berlin'))
                    ->format('d.m.Y H:i'),
                'url' => $media->mediumPath($medium),
                'thumbnail' => $preview !== null ? $imgproxy->generateUrl($preview, 240, 160) : null,
            ];
        }

        return new JsonResponse($points);
    }
}
