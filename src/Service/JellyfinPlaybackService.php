<?php

namespace App\Service;

use App\Entity\VideoScene;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class JellyfinPlaybackService
{
    private string $jellyfinHost;
    private ?string $jellyfinApiKey;

    public function __construct(
        #[Autowire('%env(JELLYFIN_HOST)%')] string $jellyfinHost,
        #[Autowire('%env(JELLYFIN_API_KEY)%')] ?string $jellyfinApiKey
    ) {
        $this->jellyfinHost = rtrim($jellyfinHost, '/');
        $this->jellyfinApiKey = $jellyfinApiKey;
    }

    public function generatePlaybackUrl(VideoScene $scene): ?string
    {
        if (!$scene->isPublic()) {
            return null;
        }

        $video = $scene->getVideo();
        if (!$video || !$video->getJellyfinItemId()) {
            return null;
        }

        if (!$this->jellyfinApiKey) {
            return null;
        }

        $itemId = $video->getJellyfinItemId();
        $startTicks = (int)($scene->getStartSeconds() * 10000000);
        
        // URL-Encoding für den API-Key falls nötig, aber Emby/Jellyfin erwartet diesen oft als Header oder Query-Param
        $params = http_build_query([
            'api_key' => $this->jellyfinApiKey,
            'StartTimeTicks' => $startTicks
        ]);

        return "{$this->jellyfinHost}/Videos/{$itemId}/stream?{$params}";
    }
}
