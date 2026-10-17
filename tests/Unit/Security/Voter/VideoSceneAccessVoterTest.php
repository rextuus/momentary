<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Video;
use App\Entity\VideoScene;
use App\Security\Voter\VideoAccessVoter;
use App\Security\Voter\VideoSceneAccessVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class VideoSceneAccessVoterTest extends TestCase
{
    use VoterTestTrait;

    public function testAdminSeesEveryScene(): void
    {
        $admin = self::user(['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(
            $admin,
            $this->scene(videoIsPublic: false),
            isAdmin: true,
        ));
    }

    public function testPublicSceneInsidePrivateVideoIsVisible(): void
    {
        $scene = $this->scene(videoIsPublic: false)->setIsPublic(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(self::user(), $scene));
    }

    public function testSceneGroupsOverrideVideoAccess(): void
    {
        $member = self::user();
        $stranger = self::user();
        // Video ist öffentlich, die Szene aber nur für eine Gruppe freigegeben
        $scene = $this->scene(videoIsPublic: true)->addAllowedGroup(self::group([$member]));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($member, $scene));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($stranger, $scene));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, $scene));
    }

    public function testWithoutSceneGroupsVideoAccessDecides(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(self::user(), $this->scene(videoIsPublic: true)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(self::user(), $this->scene(videoIsPublic: false)));
    }

    private function scene(bool $videoIsPublic): VideoScene
    {
        return new VideoScene()->setVideo(new Video()->setIsPublic($videoIsPublic));
    }

    private function vote(?\App\Entity\User $user, VideoScene $scene, bool $isAdmin = false): int
    {
        $security = $this->createStub(Security::class);
        $videoVoter = new VideoAccessVoter($security);
        $token = self::tokenFor($user);

        // Security delegiert wie im Framework an den Video-Voter
        $security
            ->method('isGranted')
            ->willReturnCallback(static function (mixed $attribute, mixed $subject = null) use (
                $isAdmin,
                $videoVoter,
                $token,
            ): bool {
                if ($attribute === 'ROLE_ADMIN') {
                    return $isAdmin;
                }

                return $videoVoter->vote($token, $subject, [$attribute]) === VoterInterface::ACCESS_GRANTED;
            });

        return new VideoSceneAccessVoter($security)->vote($token, $scene, [VideoSceneAccessVoter::VIEW]);
    }
}
