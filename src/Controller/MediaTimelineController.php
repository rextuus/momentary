<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Service\Timeline\MediaTimeline;
use App\Service\Timeline\TimelineFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zeitachse über alle sichtbaren Videos und Fotos.
 */
final class MediaTimelineController extends AbstractController
{
    #[Route('/timeline', name: 'app_media_timeline', methods: ['GET'])]
    public function index(Request $request, MediaTimeline $timeline, PersonRepository $personRepository): Response
    {
        $type = $request->query->getString('type', TimelineFilter::ALL);
        $filter = new TimelineFilter(
            (int) $request->query->getString('person') ?: null,
            \in_array($type, [TimelineFilter::VIDEOS, TimelineFilter::PHOTOS], true) ? $type : TimelineFilter::ALL,
        );
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $cursor = $request->query->get('before');
        $page = $timeline->page($user, $isAdmin, $filter, \is_string($cursor) ? $cursor : null);

        // Nachladen beim Scrollen: nur die nächsten Einträge
        if ($request->query->getBoolean('partial')) {
            return $this->render('timeline/_page.html.twig', [
                'page' => $page,
                'filter' => $filter,
                'previousMonth' => $request->query->getString('after_month'),
            ]);
        }

        return $this->render('timeline/index.html.twig', [
            'page' => $page,
            'filter' => $filter,
            'months' => $timeline->months($user, $isAdmin, $filter),
            'persons' => $personRepository->findBy(['status' => PersonStatus::IDENTIFIED], ['name' => 'ASC']),
            'selectedPerson' => $filter->personId !== null ? $personRepository->find($filter->personId) : null,
            'cursor' => $cursor,
        ]);
    }
}
