<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Svg;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;

/**
 * An SVG converted by the native converter onto an A4 page of an uncompressed PDF in memory: the
 * PDF and the warnings the conversion raised. Text is set in Helvetica, so needs no font file.
 */
final readonly class NativeRender
{
    /** @param list<string> $warnings */
    private function __construct(
        public string $pdf,
        public array $warnings,
    ) {}

    /** Converts a 10 by 10 px SVG with the given content. */
    public static function of(string $content): self
    {
        return self::ofFile(SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
                . "{$content}</svg>",
        ));
    }

    public static function ofFile(SvgFile $svg): self
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $converter = new NativeConverter($doc->fonts()->standard(StandardFont::Helvetica));
        $doc->addPage(PageSize::A4)->place($doc->svg($svg, $converter), new Box(0.0, 0.0, 100.0, 100.0));
        $doc->close();

        return new self($out->contents(), $converter->warnings());
    }
}
