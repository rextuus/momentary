<?php

namespace App\Controller\File;

use App\Service\File\FtpFileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FtpFileManagerController extends AbstractController
{
    public function __construct(
        private FtpFileManager $ftpFileManager
    ) {}

    #[Route('/admin/files/ftp', name: 'admin_files_ftp', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/files/ftp.html.twig', [
            'sourceDirs' => $this->ftpFileManager->getSourceDirs(),
        ]);
    }

    #[Route('/admin/files/ftp/list', name: 'admin_files_ftp_list', methods: ['GET'])]
    public function listFiles(Request $request): Response
    {
        $dir = $request->query->get('dir');
        
        try {
            $files = $this->ftpFileManager->listFiles($dir);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Fehler beim Auflisten der Dateien: ' . $e->getMessage());
            return $this->redirectToRoute('admin_files_ftp');
        }

        return $this->render('admin/files/ftp_list.html.twig', [
            'dir' => $dir,
            'files' => $files,
        ]);
    }

    #[Route('/admin/files/ftp/move', name: 'admin_files_ftp_move', methods: ['POST'])]
    public function moveFile(Request $request): Response
    {
        $path = $request->request->get('path');
        $dir = $request->request->get('dir');
        
        try {
            $this->ftpFileManager->moveFile($path);
            $this->addFlash('success', 'Datei erfolgreich verschoben.');
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Fehler beim Verschieben der Datei: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_files_ftp_list', ['dir' => $dir]);
    }

    #[Route('/admin/files/ftp/rename', name: 'admin_files_ftp_rename', methods: ['POST'])]
    public function renameFile(Request $request): Response
    {
        $path = $request->request->get('path');
        $newName = $request->request->get('new_name');
        $dir = $request->request->get('dir');
        
        try {
            $this->ftpFileManager->renameFile($path, $newName);
            $this->addFlash('success', 'Datei erfolgreich umbenannt.');
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Fehler beim Umbenennen der Datei: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_files_ftp_list', ['dir' => $dir]);
    }
}
