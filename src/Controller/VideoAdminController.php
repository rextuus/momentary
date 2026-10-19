<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/video')]
final class VideoAdminController extends AbstractController
{
    public function __construct() {}

    #[Route('/', name: 'app_admin_video_index')]
    public function index(VideoRepository $repo): Response
    {
        return $this->render('video/index.html.twig', [
            'videos' => $repo->findAll(),
        ]);
    }

    /**
     * Alte Adresse: Freigaben laufen jetzt über Pools (Pool-Karte auf der Videoseite).
     */
    #[Route('/{id}/groups', name: 'app_admin_video_groups', methods: ['GET'])]
    public function editGroups(Video $video): Response
    {
        return $this->redirect($this->generateUrl('app_video_show', ['id' => $video->getId()]) . '#pools');
    }
}
