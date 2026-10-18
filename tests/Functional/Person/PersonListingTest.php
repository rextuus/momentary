<?php

declare(strict_types=1);

namespace App\Tests\Functional\Person;

use App\Enum\PersonStatus;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class PersonListingTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testShowsNewAndIdentifiedPersonsByDefaultButNotHiddenOnes(): void
    {
        $client = static::createClient();
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $new = PersonFactory::createOne(['name' => 'unknown_1234', 'status' => PersonStatus::NEW]);
        PersonFactory::createOne(['name' => 'Rauschen', 'status' => PersonStatus::WASTED]);
        VideoFaceFactory::createOne([
            'person' => $anna,
            'faceImage' => FileFactory::createOne(),
            'boundingBox' => ['Left' => 0.4, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.2],
        ]);

        $html = $this->listing($client)->render()->toString();

        self::assertStringContainsString('Anna', $html);
        self::assertStringContainsString(
            sprintf('Person #%d', $new->getId()),
            $html,
            'Platzhaltername wird lesbar angezeigt',
        );
        self::assertStringNotContainsString('Rauschen', $html);
        self::assertStringContainsString(
            '/c:0.18:0.36:fp:0.45:0.4/',
            $html,
            'Vorschaubild ist auf das Gesicht zugeschnitten',
        );
    }

    public function testToggleStatusAndSearch(): void
    {
        $client = static::createClient();
        PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        PersonFactory::createOne(['name' => 'Bernd', 'status' => PersonStatus::IDENTIFIED]);
        PersonFactory::createOne(['name' => 'Rauschen', 'status' => PersonStatus::WASTED]);

        $listing = $this->listing($client)->call('toggleStatus', ['status' => 'wasted']);
        self::assertStringContainsString('Rauschen', $listing->render()->toString());

        $html = $listing->set('search', 'ann')->render()->toString();
        self::assertStringContainsString('Anna', $html);
        self::assertStringNotContainsString('Bernd', $html);
    }

    private function listing(KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('PersonListingComponent', [], $client)->actingAs(UserFactory::createOne());
    }
}
