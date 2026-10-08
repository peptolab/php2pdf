<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\Bidi;

use Php2Pdf\Font\Bidi\BidiClass;
use Php2Pdf\Font\Bidi\ExplicitLevels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_slice;

/**
 * Embeddings and isolates past the depth limit of 125 (rules X5 to X7). Sixty-two LREs take the
 * level to 124, so the next LRE or LRI overflows; the levels from that point on are compared.
 */
#[Group('unit')]
final class ExplicitLevelsTest extends TestCase
{
    private const int DEEPEST = 62;

    /** @return array<string, array{0: list<int>, 1: list<int>}> */
    public static function overflowProvider(): array
    {
        return [
            'an overflow embedding keeps the level'                  => [
                [BidiClass::LRE, BidiClass::L],
                [124,            124],
            ],
            'a PDF ends the overflow embedding first'                => [
                [BidiClass::LRE, BidiClass::PDF, BidiClass::L, BidiClass::PDF, BidiClass::L],
                [124,            124,            124,          124,            122],
            ],
            'an overflow isolate keeps the level'                    => [
                [BidiClass::LRI, BidiClass::L],
                [124,            124],
            ],
            'a PDF inside an overflow isolate is ignored'            => [
                [BidiClass::LRI, BidiClass::PDF, BidiClass::L],
                [124,            124,            124],
            ],
            'an embedding inside an overflow isolate is not counted' => [
                [BidiClass::LRI, BidiClass::LRE, BidiClass::PDI, BidiClass::PDF, BidiClass::L],
                [124,            124,            124,            124,            122],
            ],
            'a PDI ends the overflow isolate'                        => [
                [BidiClass::LRI, BidiClass::PDI, BidiClass::PDF, BidiClass::L],
                [124,            124,            124,            122],
            ],
        ];
    }

    /**
     * @param list<int> $classes
     * @param list<int> $levels
     */
    #[Test]
    #[DataProvider('overflowProvider')]
    public function explicitCodesPastTheDepthLimitAreCountedButOpenNoLevel(array $classes, array $levels): void
    {
        $explicit = new ExplicitLevels([...array_fill(0, self::DEEPEST, BidiClass::LRE), ...$classes], level: 0);

        self::assertSame($levels, array_slice($explicit->levels, offset: self::DEEPEST));
    }
}
