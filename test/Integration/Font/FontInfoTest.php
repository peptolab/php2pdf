<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\FontInfo;
use Php2PdfTest\TestAsset\Font\FontCollectionBuilder;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function pack;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** Peeking at font files: collections list every face, and faces without names are passed over. */
#[Group('integration')]
final class FontInfoTest extends TestCase
{
    private string $path = '';

    /** @return array<string, array{0: array<string, string|null>}> */
    public static function namelessFontProvider(): array
    {
        return [
            'no name table'    => [['name' => null]],
            'empty name table' => [['name' => '']],
        ];
    }

    private static function font(string $name): string
    {
        return (string) file_get_contents(PdfTools::assetPath("fonts/{$name}"));
    }

    #[Test]
    public function aCollectionFacePastTheEndOfTheFileIsSkipped(): void
    {
        self::assertSame([], $this->peek('ttcf' . pack('nnNN', 1, 0, 1, 1000)));
    }

    #[Test]
    public function aCollectionListsEachFaceWithItsIndex(): void
    {
        $collection = FontCollectionBuilder::collection([
            self::font('DejaVuSans.ttf'),
            self::font('DejaVuSans-Bold.ttf'),
        ]);

        self::assertSame(
            [
                [0, 'DejaVuSans'],
                [1, 'DejaVuSans-Bold'],
            ],
            $this->peek($collection),
        );
    }

    /** @param array<string, string|null> $changes */
    #[Test]
    #[DataProvider('namelessFontProvider')]
    public function aFaceWithoutNamesIsSkipped(array $changes): void
    {
        self::assertSame([], $this->peek(SfntTables::replace(self::font('DejaVuSans.ttf'), $changes)));
    }

    #[Test]
    public function aFileShorterThanAnSfntHeaderHasNoFaces(): void
    {
        self::assertSame([], $this->peek('OTTO'));
    }

    #[Test]
    public function aMissingFontCannotBeOpened(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Cannot open font "/nonexistent/font.ttf".');
        FontInfo::peek('/nonexistent/font.ttf');
    }

    #[Override]
    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), prefix: 'php2pdf-fontinfo-');
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** @return list<array{0: int, 1: string}> */
    private function peek(string $data): array
    {
        file_put_contents($this->path, $data);

        $faces = [];
        foreach (FontInfo::peek($this->path) as $info) {
            $faces[] = [$info->faceIndex, $info->postScriptName];
        }

        return $faces;
    }
}
