<?php

namespace App\Controller;

use App\Entity\Video;
use App\Form\VideoType;
use App\Message\InitVideoMessage;
use App\Service\Video\VideoFaceMap;
use App\Service\VideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video')]
final class VideoController extends AbstractController
{


    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    #[Route('/new', name: 'video_new')]
    public function index(Request $request, EntityManagerInterface $em, VideoAnalyzer $analyzer): Response
    {
        $video = new Video();
        $form = $this->createForm(VideoType::class, $video);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $video->setCreatedAt(new \DateTimeImmutable());

            $em->persist($video);
            $em->flush();

            $message = new InitVideoMessage($video->getId());
            $this->messageBus->dispatch($message);

            $this->addFlash('success', 'Video hinzugefügt!');
            return $this->redirectToRoute('video_new'); // or to a list
        }

        return $this->render('video/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/timeline', name: 'video_timeline')]
    public function timeline(Video $video): Response
    {
        // Get videoFaces sorted by timestamp
        $videoFaces = $video->getVideoFaces()->toArray();
//        usort($videoFaces, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $map = new VideoFaceMap();
        foreach ($videoFaces as $videoFace) {
            $map->addFace($videoFace);
        }


        return $this->render('video/timeline.html.twig', [
            'video' => $video,
            'map' => $map,
        ]);
    }

}
