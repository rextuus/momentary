<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Processing;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Sichert die Verkettung der Pipeline-Schritte ab: jeder Schritt muss genau einmal
 * und in der per #[StepOrder] dokumentierten Reihenfolge erreicht werden.
 */
final class PipelineStepChainTest extends TestCase
{
    public function testChainStartingAtConversionVisitsEveryStepOnceInStepOrder(): void
    {
        $visited = [];
        $class = ConvertStepMessage::class;

        while ($class !== null) {
            self::assertNotContains($class, $visited, sprintf('Zyklus in der Pipeline bei %s', $class));
            $visited[] = $class;
            $class = self::instantiate($class)->getNextStepMessageClass();
        }

        self::assertSame(self::allStepMessagesSortedByStepOrder(), $visited);
    }

    public function testStepNumbersAreUniqueAndGapless(): void
    {
        $numbers = array_map(self::stepNumber(...), self::allStepMessagesSortedByStepOrder());

        self::assertSame(range(1, \count($numbers)), $numbers);
    }

    public function testEveryStepPointsToAnExistingStepMessage(): void
    {
        foreach (self::allStepMessagesSortedByStepOrder() as $class) {
            $next = self::instantiate($class)->getNextStepMessageClass();
            if ($next === null) {
                continue;
            }

            self::assertTrue(
                class_exists($next) && is_subclass_of($next, VideoProcessStepMessageInterface::class),
                sprintf('%s verweist auf unbekannten Folgeschritt %s', $class, $next),
            );
        }
    }

    /**
     * @return list<class-string<VideoProcessStepMessageInterface>>
     */
    private static function allStepMessagesSortedByStepOrder(): array
    {
        $directory = \dirname(__DIR__, 5) . '/src/Service/Video/Processing/Message';
        $classes = [];

        foreach (new Finder()
            ->files()
            ->in($directory)
            ->name('*.php') as $file) {
            $class =
                'App\\Service\\Video\\Processing\\Message\\'
                . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $reflection = new \ReflectionClass($class);

            if (
                !$reflection->isAbstract() && $reflection->implementsInterface(VideoProcessStepMessageInterface::class)
            ) {
                self::assertNotEmpty(
                    $reflection->getAttributes(StepOrder::class),
                    sprintf('%s hat kein #[StepOrder]', $class),
                );
                $classes[] = $class;
            }
        }

        usort($classes, static fn(string $a, string $b): int => self::stepNumber($a) <=> self::stepNumber($b));

        return $classes;
    }

    /**
     * @param class-string $class
     */
    private static function stepNumber(string $class): int
    {
        return new \ReflectionClass($class)->getAttributes(StepOrder::class)[0]->newInstance()->getStepNumber();
    }

    /**
     * @param class-string<VideoProcessStepMessageInterface> $class
     */
    private static function instantiate(string $class): VideoProcessStepMessageInterface
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }
}
