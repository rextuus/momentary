<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Api\ApiClients;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verwalten → API-Zugänge: OAuth2-Clients für momentary-hub und momentary-android.
 */
#[Route('/admin/settings/api-clients')]
final class ApiClientsController extends AbstractController
{
    public function __construct(
        private readonly ApiClients $clients,
        #[Autowire('%env(default::APP_PUBLIC_URL)%')]
        private readonly ?string $publicUrl = null,
    ) {}

    #[Route('', name: 'app_admin_api_clients', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    #[Route('', name: 'app_admin_api_clients_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->checkToken($request);
        $identifier = $request->request->getString('identifier');
        try {
            $secret = $this->clients->create(
                $request->request->getString('name'),
                $identifier,
                $request->request->getString('secret') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_admin_api_clients');
        }

        // Secret nicht über Session/Flash transportieren, sondern einmal direkt anzeigen
        return $this->page($request, ['identifier' => trim($identifier), 'secret' => $secret]);
    }

    #[Route('/{identifier}', name: 'app_admin_api_clients_update', methods: ['POST'])]
    public function update(string $identifier, Request $request): Response
    {
        $this->checkToken($request);
        $client = $this->clients->find($identifier) ?? throw $this->createNotFoundException();

        try {
            return match ($request->request->getString('action')) {
                'secret' => $this->page($request, [
                    'identifier' => $client->getIdentifier(),
                    'secret' => $this->clients->regenerateSecret(
                        $client,
                        $request->request->getString('secret') ?: null,
                    ),
                ]),
                'activate', 'deactivate' => $this->toggle(
                    $client,
                    $request->request->getString('action') === 'activate',
                ),
                'delete' => $this->delete($client),
                default => $this->redirectToRoute('app_admin_api_clients'),
            };
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_admin_api_clients');
        }
    }

    private function toggle(ClientInterface $client, bool $active): Response
    {
        $this->clients->setActive($client, $active);
        $this->addFlash('success', sprintf(
            '„%s“ ist %s.',
            $client->getName(),
            $active ? 'wieder aktiv' : 'deaktiviert: Anmeldungen damit schlagen fehl',
        ));

        return $this->redirectToRoute('app_admin_api_clients');
    }

    private function delete(ClientInterface $client): Response
    {
        $this->clients->remove($client);
        $this->addFlash('success', sprintf('„%s“ wurde gelöscht.', $client->getName()));

        return $this->redirectToRoute('app_admin_api_clients');
    }

    /**
     * @param array{identifier: string, secret: string}|null $created
     */
    private function page(Request $request, ?array $created = null): Response
    {
        $base = trim((string) $this->publicUrl) !== ''
            ? rtrim((string) $this->publicUrl, '/')
            : $request->getSchemeAndHttpHost();
        $response = $this->render('admin/settings/api_clients.html.twig', [
            'clients' => $this->clients->all(),
            'created' => $created,
            'grants' => ApiClients::GRANTS,
            'publicBase' => $base,
            'publicUrlSet' => trim((string) $this->publicUrl) !== '',
        ]);
        // Die Seite kann ein Secret enthalten: nicht zwischenspeichern
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('api-clients', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
