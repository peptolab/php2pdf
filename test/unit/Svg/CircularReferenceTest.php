<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\Support\HostileFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match_all;

/**
 * SVG that refers back to something it is still expanding, through any mix of <use>, clip
 * paths, masks, patterns, markers and href chains, fails with a ParseException naming the loop.
 */
#[Group('unit')]
final class CircularReferenceTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function circularProvider(): array
    {
        return [
            'use of an ancestor'                           => [
                '<g id="g"><rect width="1" height="1"/><use href="#g"/></g>',
                '<use href="#g"/>',
                'use #g -> use #g',
            ],
            'clip path clipped by itself'                  => [
                '<clipPath id="c" clip-path="url(#c)"><rect width="5" height="5"/></clipPath>',
                '<rect width="10" height="10" clip-path="url(#c)"/>',
                'clip-path #c -> clip-path #c',
            ],
            'mask drawn through a use of its masked group' => [
                '<mask id="m"><use href="#g"/></mask><g id="g" mask="url(#m)"><rect width="5" height="5"/></g>',
                '<use href="#g"/>',
                'use #g -> mask #m -> use #g',
            ],
            'pattern painted by its own marker'            => [
                '<marker id="k"><rect width="2" height="2" fill="url(#p)"/></marker>'
                    . '<pattern id="p" patternUnits="userSpaceOnUse" width="4" height="4">'
                    . '<path d="M0 0 L4 4" stroke="black" marker-end="url(#k)"/></pattern>',
                '<rect width="10" height="10" fill="url(#p)"/>',
                'pattern #p -> marker #k -> pattern #p',
            ],
            'gradient href chain'                          => [
                '<linearGradient id="a" href="#b"/><linearGradient id="b" href="#a"><stop offset="0"/></linearGradient>',
                '<rect width="10" height="10" fill="url(#a)"/>',
                'href #a -> href #b -> href #a',
            ],
        ];
    }

    private static function convert(string $svg): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)
            ->place(
                $doc->svg(SvgFile::fromData($svg), new NativeConverter()),
                new Box(0.0, 0.0, 100.0, 100.0),
                Fit::entire(),
            );
        $doc->close();

        return $out->contents();
    }

    #[Test]
    public function aMaskWhoseChildrenUseItIsRejectedAtOnce(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('mask #m -> mask #m');
        self::convert(HostileFiles::maskFanOut());
    }

    #[Test]
    #[DataProvider('circularProvider')]
    public function circularReferencesAreRejectedWithTheLoopNamed(string $defs, string $content, string $loop): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($loop);
        self::convert(HostileFiles::svg($defs, $content));
    }

    #[Test]
    public function clipPathsThatClipEachOtherAreRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('clip-path #a -> clip-path #b -> clip-path #a');
        self::convert(HostileFiles::clipCycle());
    }

    #[Test]
    public function referencesRepeatedSideBySideAreNotCircular(): void
    {
        $pdf = self::convert(HostileFiles::svg(
            '<clipPath id="c"><rect width="5" height="5"/></clipPath>'
                . '<g id="g" clip-path="url(#c)"><rect width="2" height="2" fill="lime"/></g>'
                . '<g id="pair"><use href="#g"/><use href="#g" x="4"/></g>',
            '<use href="#pair"/><use href="#pair" y="4"/>',
        ));

        self::assertSame(4, preg_match_all('#0 1 0 rg#', $pdf));
    }
}
