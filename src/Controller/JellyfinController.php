<?php

namespace App\Controller;

use App\Entity\Video;
use App\Message\ExportVideoToJellyfinMessage;
use App\Message\OptimizeVideoForJellyfinMessage;
use App\Service\WorkflowMachine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class JellyfinController extends AbstractController
{
    #[Route('/jellyfin/export/{id}', name: 'app_jellyfin_export', methods: ['POST'])]
    public function export(Video $video, MessageBusInterface $bus, WorkflowMachine $workflowMachine): Response
    {
        if ($workflowMachine->can($video, 'start_optimization')) {
            $bus->dispatch(new OptimizeVideoForJellyfinMessage($video->getId()));
            $this->addFlash('success', 'Die Optimierung und der Export nach Jellyfin wurden gestartet.');
        } else {
            $bus->dispatch(new ExportVideoToJellyfinMessage($video->getId()));
            $this->addFlash('success', 'Der Export nach Jellyfin wurde gestartet.');
        }

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }
}
