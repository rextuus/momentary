<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class UserAgentSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $userAgent = $request->headers->get('User-Agent', '');

        $isNative = false;
        if (str_contains($userAgent, 'Turbo Native') || str_contains($userAgent, 'UX Native')) {
            $isNative = true;
        }

        // Wir stellen die Information als Request-Attribut bereit
        $request->attributes->set('is_native_app', $isNative);
        
        // Optional: Auch im Service-Container oder als globales Flag, 
        // aber Request-Attribute sind der Symfony-Standard für Request-spezifischen Kontext.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 10]],
        ];
    }
}
