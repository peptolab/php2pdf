<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Core;

use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function basename;
use function chr;
use function file_get_contents;
use function glob;
use function mt_rand;
use function mt_srand;
use function sprintf;
use function strlen;
use function substr;
use function substr_replace;

/**
 * Damaged copies of the test corpus (bytes overwritten, files cut short, spans duplicated) must
 * fail with a PdfException, never with an engine error, a warning or a crash. Mutations come from
 * a fixed seed, so a failure names a case that can be rerun.
 */
#[Group('integration')]
final class FuzzTest extends TestCase
{
    private const int CASES_PER_FILE = 100;

    /** @return array<string, array{0: string, 1: string}> */
    public static function corpusProvider(): array
    {
        $files = [];
        foreach (['pdf/*.pdf', 'images/*.png', 'svg/*.svg'] as $pattern) {
            foreach (glob(PdfTools::assetPath($pattern)) ?? [] as $path) {
                $files[basename($path)] = [$path, substr($pattern, offset: 0, length: 3)];
            }
        }

        return $files;
    }

    private static function damage(string $data): string
    {
        $length = strlen($data);

        $kind = mt_rand(
            min: 0,
            max: 2,
        );
        if (0 === $kind) {
            return self::overwrite($data, $length);
        }
        if (1 === $kind) {
            return substr($data, offset: 0, length: mt_rand(
                min: 0,
                max: $length,
            ));
        }
        $span = substr(
            $data,
            mt_rand(
                min: 0,
                max: $length - 1,
            ),
            mt_rand(
                min: 1,
                max: 64,
            ),
        );

        return substr_replace(
            $data,
            $span,
            mt_rand(
                min: 0,
                max: $length,
            ),
            length: 0,
        );
    }

    private static function open(string $data, string $kind, Limits $limits): void
    {
        if ('pdf' === $kind) {
            $file = PdfFile::fromData($data, $limits);
            $file->info();
            for ($p = 1; $p <= $file->pageCount(); ++$p) {
                $file->page($p)->content();
                $file->page($p)->resources();
            }

            return;
        }
        if ('ima' === $kind) {
            ImageReader::read($data, $limits);

            return;
        }
        (new Document(new MemoryOutput()))->svg(SvgFile::fromData($data, null, $limits), new NativeConverter());
    }

    private static function overwrite(string $data, int $length): string
    {
        for (
            $i = mt_rand(
                min: 1,
                max: 16,
            );
            $i > 0;
            --$i
        ) {
            $data[mt_rand(
                min: 0,
                max: $length - 1,
            )] = chr(mt_rand(
                min: 0,
                max: 255,
            ));
        }

        return $data;
    }

    #[Test]
    #[DataProvider('corpusProvider')]
    public function damagedFilesFailOnlyWithPdfExceptions(string $path, string $kind): void
    {
        $original = (string) file_get_contents($path);
        $limits   = new Limits(
            maxDecodedBytes: 16_000_000,
            maxImagePixels: 4_000_000,
            maxPages: 1000,
        );
        for ($case = 0; $case < self::CASES_PER_FILE; ++$case) {
            mt_srand(($case * 7919) + strlen($original));
            $damaged = self::damage($original);
            try {
                self::open($damaged, $kind, $limits);
            } catch (PdfException) {
                continue;
            } catch (Throwable $e) {
                self::fail(sprintf('%s case %d: %s: %s', basename($path), $case, $e::class, $e->getMessage()));
            }
        }
        $this->addToAssertionCount(1);
    }
}
