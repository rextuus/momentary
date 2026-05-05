<?php

namespace App\Controller;

use App\Repository\PersonRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(PersonRepository $personRepo, VideoRepository $videoRepo): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'stats' => [
                'persons' => $personRepo->count([]),
                'videos' => $videoRepo->count([]),
                'identified' => $personRepo->count(['status' => 'identified']),
            ],
            'recent_persons' => $personRepo->findBy(['status' => 'identified'], ['id' => 'DESC'], 5),
            // Die neuesten 5 Videos für das Dashboard
            'recent_videos' => $videoRepo->findBy([], ['id' => 'DESC'], 5),
        ]);
    }
}