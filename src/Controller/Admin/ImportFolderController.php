<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Media\ImportFolder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import-Ordner: Videos und Fotos aus `storage/imports` übernehmen (ersetzt den alten Video-Import).
 */
#[Route('/admin/import')]
final class ImportFolderController extends AbstractController
{
    #[Route('', name: 'app_admin_import', methods: ['GET'])]
    public function index(ImportFolder $importFolder): Response
    {
        $files = $importFolder->files();
        $groups = [];
        foreach ($files as $file) {
            $groups[$file->batch ?? ''][] = $file;
        }

        return $this->render('admin/import_folder.html.twig', [
            'groups' => $groups,
            'count' => \count($files),
            'root' => $importFolder->root(),
            'watched' => $importFolder->isWatched(),
        ]);
    }

    #[Route('', name: 'app_admin_import_start', methods: ['POST'])]
    public function start(Request $request, ImportFolder $importFolder): Response
    {
        if (!$this->isCsrfTokenValid('import-folder', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $user = $this->getUser();
        $selected = array_values(array_map(strval(...), $request->request->all('files')));
        $count = $importFolder->queue($user instanceof User ? $user : null, $selected);
        $this->addFlash(
            ...$count > 0
                ? [
                    'success',
                    sprintf(
                        '%d Datei(en) werden im Hintergrund übernommen. Seite neu laden, um den Stand zu sehen.',
                        $count,
                    ),
                ]
                : ['warning', 'Keine Datei ausgewählt.'],
        );

        return $this->redirectToRoute('app_admin_import');
    }
}
