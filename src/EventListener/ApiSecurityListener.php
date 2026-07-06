<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ApiSecurityListener
{
    public function __construct(private ParameterBagInterface $params)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only secure /api paths
        if (!str_starts_with($path, '/api')) {
            return;
        }

        $token = $request->headers->get('X-AUTH-TOKEN');
        $apiKey = $this->params->get('API_KEY');

        if ($token !== $apiKey) {
            throw new AccessDeniedHttpException('Invalid API Token');
        }
    }
}
