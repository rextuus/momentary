<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CameraClockOffset;
use App\Service\Media\CameraClock;
use App\Service\Media\CameraOverview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kamera-Uhren: Kameras mit Zeitraum, gemerkte Abweichungen, unplausible Aufnahmezeiten.
 */
#[Route('/admin/camera-clocks')]
final class CameraClockController extends AbstractController
{
    #[Route('', name: 'app_admin_camera_clocks', methods: ['GET'])]
    public function index(
        CameraClock $cameraClock,
        CameraOverview $overview,
        EntityManagerInterface $entityManager,
    ): Response {
        $offsets = [];
        foreach ($entityManager->getRepository(CameraClockOffset::class)->findBy([], ['id' => 'DESC']) as $offset) {
            $offsets[] = ['offset' => $offset, 'pending' => \count($cameraClock->pending($offset))];
        }

        return $this->render('admin/camera_clocks.html.twig', [
            'cameras' => $overview->cameras(),
            'offsets' => $offsets,
            'implausible' => $overview->implausible(),
        ]);
    }

    #[Route('/{id}/apply', name: 'app_admin_camera_clocks_apply', methods: ['POST'])]
    public function apply(CameraClockOffset $offset, Request $request, CameraClock $cameraClock): Response
    {
        $this->checkToken('apply-clock' . $offset->getId(), $request);
        $count = $cameraClock->applyToExisting($offset);
        $this->addFlash('success', sprintf('%d Medien um %s verschoben.', $count, $offset->getOffsetLabel()));

        return $this->redirectToRoute('app_admin_camera_clocks');
    }

    #[Route('/{id}/delete', name: 'app_admin_camera_clocks_delete', methods: ['POST'])]
    public function delete(CameraClockOffset $offset, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->checkToken('delete-clock' . $offset->getId(), $request);
        $entityManager->remove($offset);
        $entityManager->flush();
        $this->addFlash('info', 'Abweichung gelöscht. Bereits verschobene Medien bleiben, wie sie sind.');

        return $this->redirectToRoute('app_admin_camera_clocks');
    }

    private function checkToken(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
