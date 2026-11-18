<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Service\Face\Embedding\EmbeddingMath;
use PHPUnit\Framework\TestCase;

final class EmbeddingMathTest extends TestCase
{
    public function testPackAndUnpackKeepFloat32Precision(): void
    {
        $vector = EmbeddingMath::normalize(array_map(static fn(int $i): float => sin($i), range(1, 512)));

        $binary = EmbeddingMath::pack($vector);
        $restored = EmbeddingMath::unpack($binary);

        self::assertSame(2048, \strlen($binary), '512 × float32');
        self::assertCount(512, $restored);
        foreach ($vector as $i => $value) {
            self::assertEqualsWithDelta($value, $restored[$i], 1e-6);
        }
        self::assertEqualsWithDelta(1.0, EmbeddingMath::cosine($vector, $restored), 1e-6);
    }

    public function testPackIsLittleEndianRegardlessOfPlatform(): void
    {
        self::assertSame("\x00\x00\x80\x3f", EmbeddingMath::pack([1.0]));
    }

    public function testUnpackRejectsBrokenData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmbeddingMath::unpack("\x00\x00\x80");
    }

    public function testCosine(): void
    {
        self::assertEqualsWithDelta(1.0, EmbeddingMath::cosine([1.0, 2.0], [2.0, 4.0]), 1e-9, 'gleiche Richtung');
        self::assertEqualsWithDelta(0.0, EmbeddingMath::cosine([1.0, 0.0], [0.0, 3.0]), 1e-9, 'senkrecht');
        self::assertEqualsWithDelta(-1.0, EmbeddingMath::cosine([1.0, 1.0], [-1.0, -1.0]), 1e-9, 'entgegengesetzt');
        self::assertSame(0.0, EmbeddingMath::cosine([0.0, 0.0], [1.0, 0.0]), 'Nullvektor');
    }

    public function testDotRejectsDifferentLengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmbeddingMath::dot([1.0, 2.0], [1.0]);
    }

    public function testNormalize(): void
    {
        self::assertSame([0.6, 0.8], EmbeddingMath::normalize([3.0, 4.0]));
        self::assertSame([0.0, 0.0], EmbeddingMath::normalize([0.0, 0.0]));
    }

    public function testTopKSortsFiltersAndLimits(): void
    {
        $query = EmbeddingMath::normalize([1.0, 0.0]);
        $references = [
            'gleich' => EmbeddingMath::normalize([1.0, 0.0]),
            'nah' => EmbeddingMath::normalize([1.0, 0.3]),
            'mittel' => EmbeddingMath::normalize([1.0, 1.0]),
            'fremd' => EmbeddingMath::normalize([0.0, 1.0]),
        ];

        $hits = EmbeddingMath::topK($query, $references, 0.5, 2);

        self::assertSame(['gleich', 'nah'], array_keys($hits));
        self::assertEqualsWithDelta(1.0, $hits['gleich'], 1e-9);
        self::assertSame(
            ['gleich', 'nah', 'mittel'],
            array_keys(EmbeddingMath::topK($query, $references, 0.5, 10)),
            'unter der Schwelle fällt weg',
        );
    }

    public function testTopKKeepsIntegerKeys(): void
    {
        $hits = EmbeddingMath::topK([1.0, 0.0], [17 => [0.6, 0.8], 4 => [1.0, 0.0]], 0.0, 5);

        self::assertSame([4, 17], array_keys($hits));
    }
}
