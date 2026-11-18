<?php

declare(strict_types=1);

namespace App\Service\Face\Provider;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Alle Anbieter der Gesichtserkennung; neue Bilder analysiert der in FACE_RECOGNITION_PROVIDER eingestellte.
 *
 * Gespeicherte Gesichter merken sich ihren Anbieter (VideoFace::provider). Beim Löschen wird deshalb der Anbieter
 * gefragt, der das Gesicht angelegt hat, nicht der gerade eingestellte.
 */
class FaceRecognitionProviders
{
    public function __construct(
        #[AutowireLocator(FaceRecognitionProviderInterface::TAG, defaultIndexMethod: 'getName')]
        private readonly ContainerInterface $providers,
        #[Autowire('%env(FACE_RECOGNITION_PROVIDER)%')]
        private readonly string $active,
    ) {}

    public function current(): FaceRecognitionProviderInterface
    {
        return $this->get($this->active);
    }

    public function activeName(): string
    {
        return $this->active;
    }

    public function get(string $name): FaceRecognitionProviderInterface
    {
        if (!$this->providers->has($name)) {
            throw new \InvalidArgumentException(sprintf(
                'Unbekannter Anbieter der Gesichtserkennung "%s" (FACE_RECOGNITION_PROVIDER).',
                $name,
            ));
        }
        $provider = $this->providers->get($name);
        \assert($provider instanceof FaceRecognitionProviderInterface, 'Der Locator enthält nur getaggte Anbieter.');

        return $provider;
    }
}
