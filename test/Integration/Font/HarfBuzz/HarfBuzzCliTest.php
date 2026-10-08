<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\HarfBuzz;

use Override;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Font\HarfBuzz\HarfBuzzCli;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function array_diff;
use function array_intersect;
use function array_values;
use function chmod;
use function count;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function gc_collect_cycles;
use function glob;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** HarfBuzz through hb-shape: each font is written to one temporary file, removed with the font. */
#[Group('integration')]
final class HarfBuzzCliTest extends TestCase
{
    private HarfBuzzCli $shaper;
    private ?string $script = null;

    private static function font(): OpenTypeFont
    {
        return OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath(
            'fonts/SourceSerif4-Regular.ttf',
        )));
    }

    /** @return list<string> */
    private static function temporaryFiles(): array
    {
        $files = glob(sys_get_temp_dir() . '/php2pdf-hb-*');

        return false === $files ? [] : $files;
    }

    #[Test]
    public function aFailingCommandReportsWhatItPrinted(): void
    {
        $this->script = (string) tempnam(sys_get_temp_dir(), prefix: 'php2pdf-fake-hb-');
        file_put_contents($this->script, data: "#!/bin/sh\necho 'no such font' >&2\nexit 1\n");
        chmod($this->script, permissions: 0o755);

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("\"{$this->script}\" failed: no such font");

        (new HarfBuzzCli($this->script))->shape(self::font(), 'fi', 'Latn', TextDirection::LeftToRight, []);
    }

    #[Test]
    public function aFontShapedTwiceIsWrittenOnce(): void
    {
        $before = self::temporaryFiles();
        $font   = self::font();
        $this->shaper->shape($font, 'fi', 'Latn', TextDirection::LeftToRight, []);
        $this->shaper->shape($font, 'fl', 'Latn', TextDirection::LeftToRight, []);

        self::assertCount(1, array_diff(self::temporaryFiles(), $before));
    }

    #[Test]
    public function aFontsTemporaryFileGoesWithTheFont(): void
    {
        $before = self::temporaryFiles();
        $font   = self::font();
        $this->shaper->shape($font, 'fi', 'Latn', TextDirection::LeftToRight, []);
        $written   = array_diff(self::temporaryFiles(), $before);
        $reference = WeakReference::create($font);
        unset($font);
        gc_collect_cycles();

        self::assertSame([1, null, []], [
            count($written),
            $reference->get(),
            array_values(array_intersect($written, self::temporaryFiles())),
        ]);
    }

    #[Test]
    public function aTurkishLanguageKeepsFAndIApart(): void
    {
        $glyphs = $this->shaper->shape(self::font(), 'fi', 'Latn', TextDirection::LeftToRight, [], 'tr');

        self::assertSame(
            [
                [33,  0, 384, 0, 0, 0],
                [298, 1, 298, 0, 0, 0],
            ],
            $glyphs,
        );
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
        $output = [];
        $status = 1;
        exec('command -v hb-shape', $output, $status);
        if (0 !== $status) {
            self::markTestSkipped('hb-shape is not installed.');
        }
        $this->shaper = new HarfBuzzCli();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->script && is_file($this->script)) {
            unlink($this->script);
        }
    }
}
