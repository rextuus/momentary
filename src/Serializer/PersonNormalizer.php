<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Person;
use App\Service\ImgproxyService;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class PersonNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'PERSON_NORMALIZER_ALREADY_CALLED';

    public function __construct(private ImgproxyService $imgproxyService) {}

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        // 1. Schranke für diesen spezifischen Durchlauf schließen
        $context[self::ALREADY_CALLED] = true;

        // 2. An den Core-Serializer von API Platform übergeben
        $data = $this->normalizer->normalize($object, $format, $context);

        // 3. URL mit ImgProxy manipulieren
        if (is_array($data) && isset($data['profileImageUrl']) && $object instanceof Person) {
            $groups = $context['groups'] ?? [];

            $width = in_array('person:list', $groups) ? 150 : 400;
            $height = in_array('person:list', $groups) ? 150 : 400;

            $data['profileImageUrl'] = $this->imgproxyService->generateUrl(
                $data['profileImageUrl'],
                $width,
                $height
            );
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        // Wenn die Schranke aktiv ist, diesen Normalizer überspringen
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Person;
    }

    public function getSupportedTypes(?string $format): array
    {
        // WICHTIG: Hier MUSS false stehen!
        // Das signalisiert Symfony, dass der Support dynamisch ist und supportsNormalization() aufgerufen werden MUSS.
        return [
            Person::class => false,
        ];
    }
}
