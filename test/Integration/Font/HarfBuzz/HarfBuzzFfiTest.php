<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\HarfBuzz;

use Override;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\HarfBuzz\HarfBuzzFfi;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function file_get_contents;
use function gc_collect_cycles;

/** HarfBuzz through FFI: features it cannot read are refused, and fonts are not kept alive. */
#[Group('integration')]
final class HarfBuzzFfiTest extends TestCase
{
    private HarfBuzzFfi $shaper;

    private static function font(): OpenTypeFont
    {
        return OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath(
            'fonts/SourceSerif4-Regular.ttf',
        )));
    }

    #[Test]
    public function aFeatureHarfBuzzCannotReadIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HarfBuzz cannot read the feature "+".');

        $this->shaper->shape(self::font(), 'fi', 'Latn', TextDirection::LeftToRight, ['-liga', '+']);
    }

    #[Test]
    public function aFontLoadedAgainShapesAsBefore(): void
    {
        $first = $this->shaper->shape(self::font(), 'fi ilk', 'Latn', TextDirection::LeftToRight, []);
        gc_collect_cycles();

        self::assertSame($first, $this->shaper->shape(self::font(), 'fi ilk', 'Latn', TextDirection::LeftToRight, []));
    }

    #[Test]
    public function aShapedFontIsReleasedWithItsLastReference(): void
    {
        $font = self::font();
        $this->shaper->shape($font, 'fi', 'Latn', TextDirection::LeftToRight, []);
        $reference = WeakReference::create($font);
        unset($font);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    #[Test]
    public function featuresTurnLookupsOff(): void
    {
        $glyphs = $this->shaper->shape(self::font(), 'fi', 'Latn', TextDirection::LeftToRight, ['-liga']);

        self::assertSame([[33, 0, 384, 0, 0, 0], [36, 1, 298, 0, 0, 0]], $glyphs);
    }

    #[Override]
    protected function setUp(): void
    {
        try {
            $this->shaper = new HarfBuzzFfi();
        } catch (PdfException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }
}
