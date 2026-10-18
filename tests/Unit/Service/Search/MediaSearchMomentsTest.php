<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Service\Search\MediaSearch;
use App\Service\Search\SearchMoment;
use App\Twig\Extension\MediaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MediaSearchMomentsTest extends TestCase
{
    private const array HIT = [
        'id' => '8',
        'transcript' => [
            ['start' => 27, 'end' => 29, 'text' => 'Du könntest Butter haben.'],
            ['start' => 45, 'end' => 46, 'text' => 'Da ist ja kein Fett dran.'],
            ['start' => 60, 'end' => 62, 'text' => 'Schmeckt schon.'],
        ],
        'persons_with_scenes' => [[
            'name' => 'Joscha',
            'start' => 30,
            'end' => 50,
            'thumbnailUrl' => 'images/videos/8/a.jpg',
        ]],
        'tags_with_scenes' => [['name' => 'Essen', 'start' => 0, 'end' => 120, 'thumbnailUrl' => null]],
    ];

    public function testSpokenMatchesAndSelectedFiltersBecomeMomentsInTimeOrder(): void
    {
        $moments = MediaSearch::moments(self::HIT, MediaSearch::terms('Butter, Fett!'), ['Joscha'], []);

        self::assertSame(
            [
                [SearchMoment::SPOKEN, 27],
                [SearchMoment::PERSON, 30],
                [SearchMoment::SPOKEN, 45],
            ],
            array_map(static fn(SearchMoment $m): array => [$m->kind, $m->seconds], $moments),
        );
        self::assertSame('images/videos/8/a.jpg', $moments[1]->thumbnailPath);
        self::assertSame('0:45', $moments[2]->getTime());
    }

    public function testTagMatchesTheQueryToo(): void
    {
        $moments = MediaSearch::moments(self::HIT, MediaSearch::terms('essen'), [], []);

        self::assertSame([SearchMoment::TAG], array_map(static fn(SearchMoment $m): string => $m->kind, $moments));
    }

    public function testTagsOfTheSameSceneShareOneLine(): void
    {
        $hit = [
            'tags_with_scenes' => [
                ['name' => 'Pool', 'start' => 0],
                ['name' => 'Swimmingpool', 'start' => 0],
                ['name' => 'Pool', 'start' => 0],
                ['name' => 'Poolreiniger', 'start' => 131],
            ],
        ];

        $moments = MediaSearch::moments($hit, ['pool'], [], []);

        self::assertSame(
            ['Pool, Swimmingpool', 'Poolreiniger'],
            array_map(static fn(SearchMoment $m): string => $m->text, $moments),
        );
    }

    public function testShortWordsAreIgnored(): void
    {
        self::assertSame(['oma', 'butter'], MediaSearch::terms('da Oma Butter'));
    }

    public function testHighlightEscapesHtml(): void
    {
        $extension = new MediaExtension($this->createStub(UrlGeneratorInterface::class));

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt; <mark>Butter</mark> ist <mark>butter</mark>', $extension->highlight('<script>alert(1)</script> Butter ist butter', [
            'butter',
        ]));
    }
}
