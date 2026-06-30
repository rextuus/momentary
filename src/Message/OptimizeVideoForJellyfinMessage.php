<?php
/*
 * This file is part of the momentary package.
 *
 * (c) Wolfgang <wolfgang@example.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Message;

class OptimizeVideoForJellyfinMessage
{
    public function __construct(
        private readonly int $videoId
    ) {
    }

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
