<?php

namespace App\Controller;

use App\Entity\Video;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

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

    #[Route('/{id}/groups', name: 'app_admin_video_groups', methods: ['GET', 'POST'])]
    public function editGroups(Video $video, Request $request): Response
    {
        $form = $this->createForm(\App\Form\VideoGroupsType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            return $this->redirectToRoute('app_video_index');
        }

        return $this->render('admin/video/groups.html.twig', [
            'video' => $video,
            'form' => $form,
        ]);
    }
}