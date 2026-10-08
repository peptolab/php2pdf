<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\OpenType;

use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\OpenType\TrueTypeSubsetter;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function str_repeat;

#[Group('integration')]
final class TrueTypeSubsetterTest extends TestCase
{
    #[Test]
    public function aFontWithoutAPostTableGetsAnEmptyFormat3Post(): void
    {
        $data   = (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $font   = OpenTypeFont::fromData(SfntTables::replace($data, ['post' => null]));
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [36 => true]));

        self::assertSame("\0\x03\0\0" . str_repeat("\0", times: 28), $subset->table('post'));
    }
}
