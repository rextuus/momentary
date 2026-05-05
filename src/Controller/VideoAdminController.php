<?php

namespace App\Controller;

use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/video')]
class VideoAdminController extends AbstractController
{
    public function __construct(
        private VideoRepository $videoRepository,
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_video_index')]
    public function index(VideoRepository $repo): Response
    {
        return $this->render('video/index.html.twig', [
            'videos' => $repo->findAll(),
        ]);
    }
}