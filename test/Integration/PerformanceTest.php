<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Gray;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\Column;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function filesize;
use function hrtime;
use function memory_get_peak_usage;
use function microtime;
use function sprintf;
use function str_repeat;

/**
 * A 500-page document: flowing text, a table on every page, a template and an image, written
 * in bounded memory because pages stream out as they finish.
 */
#[Group('integration')]
#[Group('slow')]
final class PerformanceTest extends TestCase
{
    /**
     * Shaping keeps one buffer for a run and changes it in place, so four times the text takes
     * about four times as long; a copy per substitution made it sixteen.
     */
    #[Test]
    #[Group('performance')]
    public function shapingTimeGrowsWithTheTextNotItsSquare(): void
    {
        $document = new Document(new MemoryOutput());
        $font     = $document->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $text     = str_repeat('The quick brown fox affirms fluffy waffles, officially. ', times: 40);
        $time     = static function (string $text) use ($font): float {
            $start = hrtime(true);
            $font->shape($text);

            return (float) (hrtime(true) - $start);
        };
        $time("{$text}warm");
        $short = $time("{$text}a");
        $long  = $time(str_repeat($text, times: 4) . 'b');

        self::assertLessThan(
            8.0,
            $long / $short,
            sprintf('Four times the text took %.1f times as long.', $long / $short),
        );
    }

    #[Test]
    #[Group('performance')]
    public function writesFiveHundredPagesInBoundedTimeAndMemory(): void
    {
        $path   = PdfTools::outputDir() . '/five-hundred-pages.pdf';
        $before = memory_get_peak_usage(true);
        $start  = microtime(true);

        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $sans = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $bold = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans-Bold.ttf'));
        $doc->styles()->define('body', new TextStyle(
            font: $sans,
            size: 9.5,
            leading: Leading::pt(13.0),
            bold: $bold,
        ));
        $doc->styles()->define(
            'h2',
            new TextStyle(
                font: $bold,
                size: 14.0,
                spaceAfter: 6.0,
            ),
            basedOn: 'body',
        );
        $photo    = $doc->image(PdfTools::assetPath('images/rgb.jpg'));
        $template = $doc->template(PageSize::A4->dimensions());
        $template->canvas()->setFillColor(new Gray(0.9))->fillRect(0.0, 800.0, 595.0, 42.0);
        $copy = file_get_contents(PdfTools::assetPath('text/brochure.txt'));
        self::assertNotFalse($copy);
        $flow  = $doc->textflow(str_repeat("{$copy}\n\n", times: 12), 'body', TextFormat::Plain);
        $pages = 0;
        while ($pages < 500) {
            if ($flow->isDone()) {
                $flow->reset();
            }
            ++$pages;
            $page = $doc->addPage(PageSize::A4);
            $page->place($template, $page->mediaBox(), Fit::entire());
            $page->place($photo, Box::fromMm(150.0, 270.0, 45.0, 20.0), Fit::meet());
            $page->fitTextflow(
                $doc->textflow("<p style=\"h2\">Page {$pages}</p>", 'body', TextFormat::Markup),
                Box::fromMm(15.0, 268.0, 100.0, 12.0),
            );
            $table = $doc->table([Column::mm(30.0), Column::auto(), Column::mm(25.0)], null, 'body');
            $table->addHeaderRow(['Key', 'Description', 'Value']);
            for ($i = 0; $i < 4; ++$i) {
                $table->addRow([
                    "k{$pages}.{$i}",
                    'A short description of the row that may wrap once.',
                    sprintf('%d', $i * $pages),
                ]);
            }
            $page->fitTable($table, Box::fromMm(15.0, 215.0, 180.0, 45.0));
            $page->fitTextflow($flow, Box::fromMm(15.0, 20.0, 85.0, 190.0));
            $page->fitTextflow($flow, Box::fromMm(110.0, 20.0, 85.0, 190.0));
        }
        $doc->close();

        $seconds = microtime(true) - $start;
        $peakMiB = (memory_get_peak_usage(true) - $before) / 1_048_576;
        self::assertSame(500, $pages);
        self::assertSame('500', PdfTools::info($path)['Pages'] ?? null);
        self::assertLessThan(60.0, $seconds, sprintf('500 pages took %.1f s.', $seconds));
        self::assertLessThan(96.0, $peakMiB, sprintf('500 pages used %.1f MiB.', $peakMiB));
        $size = filesize($path);
        self::assertNotFalse($size);
        self::assertLessThan(6_000_000, $size, 'The image and template are written once.');
        PdfTools::assertValid($path);
    }
}
