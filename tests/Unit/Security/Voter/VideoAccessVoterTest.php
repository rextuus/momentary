<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Video;
use App\Security\Voter\VideoAccessVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class VideoAccessVoterTest extends TestCase
{
    use VoterTestTrait;

    /**
     * @return iterable<string, array{string, bool, bool, bool, bool, int}>
     */
    public static function accessMatrix(): iterable
    {
        // Attribut, Admin?, Eigentümer?, öffentlich?, Gruppenmitglied?, erwartetes Ergebnis
        yield 'Admin sieht private Videos' => [
            VideoAccessVoter::VIEW,
            true,
            false,
            false,
            false,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Eigentümer sieht eigenes Video' => [
            VideoAccessVoter::VIEW,
            false,
            true,
            false,
            false,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Jeder sieht öffentliche Videos' => [
            VideoAccessVoter::VIEW,
            false,
            false,
            true,
            false,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Gruppenmitglied sieht freigegebenes Video' => [
            VideoAccessVoter::VIEW,
            false,
            false,
            false,
            true,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Fremde sehen private Videos nicht' => [
            VideoAccessVoter::VIEW,
            false,
            false,
            false,
            false,
            VoterInterface::ACCESS_DENIED,
        ];

        yield 'Admin darf bearbeiten' => [
            VideoAccessVoter::EDIT,
            true,
            false,
            false,
            false,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Eigentümer darf bearbeiten' => [
            VideoAccessVoter::EDIT,
            false,
            true,
            false,
            false,
            VoterInterface::ACCESS_GRANTED,
        ];
        yield 'Öffentlich heißt nicht bearbeitbar' => [
            VideoAccessVoter::EDIT,
            false,
            false,
            true,
            false,
            VoterInterface::ACCESS_DENIED,
        ];
        yield 'Gruppenmitglied darf nicht bearbeiten' => [
            VideoAccessVoter::EDIT,
            false,
            false,
            false,
            true,
            VoterInterface::ACCESS_DENIED,
        ];
    }

    #[DataProvider('accessMatrix')]
    public function testAccessMatrix(
        string $attribute,
        bool $isAdmin,
        bool $isOwner,
        bool $isPublic,
        bool $isGroupMember,
        int $expected,
    ): void {
        $user = self::user($isAdmin ? ['ROLE_ADMIN'] : []);
        $video = new Video()
            ->setIsPublic($isPublic)
            ->setOwner($isOwner ? $user : self::user());
        if ($isGroupMember) {
            $video->addAllowedGroup(self::group([self::user(), $user]));
        }

        self::assertSame($expected, $this->voter($isAdmin)->vote(self::tokenFor($user), $video, [$attribute]));
    }

    public function testAnonymousOnlySeesPublicVideos(): void
    {
        $voter = $this->voter(false);
        $token = self::tokenFor(null);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(
            $token,
            new Video()->setIsPublic(true),
            [VideoAccessVoter::VIEW],
        ));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            $token,
            new Video()->setIsPublic(false),
            [VideoAccessVoter::VIEW],
        ));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            $token,
            new Video()->setIsPublic(true),
            [VideoAccessVoter::EDIT],
        ));
    }

    public function testVideoWithoutOwnerIsNotEditableByUsers(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter(false)->vote(
            self::tokenFor(self::user()),
            new Video(),
            [VideoAccessVoter::EDIT],
        ));
    }

    public function testAbstainsForOtherAttributesAndSubjects(): void
    {
        $voter = $this->voter(false);
        $token = self::tokenFor(self::user());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new Video(), ['VIDEO_DELETE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(
            $token,
            new \stdClass(),
            [VideoAccessVoter::VIEW],
        ));
    }

    private function voter(bool $isAdmin): VideoAccessVoter
    {
        $security = $this->createStub(Security::class);
        $security
            ->method('isGranted')
            ->willReturnCallback(static fn(mixed $attribute): bool => $attribute === 'ROLE_ADMIN' && $isAdmin);

        return new VideoAccessVoter($security);
    }
}
