<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Ascii85;
use Php2Pdf\Core\Filter\AsciiHex;
use Php2Pdf\Core\Filter\FilterChain;
use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Filter\Lzw;
use Php2Pdf\Core\Filter\Predictor;
use Php2Pdf\Core\Filter\RunLength;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;

#[Group('unit')]
final class FiltersTest extends TestCase
{
    #[Test]
    public function appliesChainsWithParameters(): void
    {
        $raw     = "\x01\x02\x03\x02\x03\x04";
        $rows    = "\x02\x01\x02\x03\x02\x01\x01\x01";
        $encoded = AsciiHex::class;
        $data    = bin2hex(Flate::encode($rows)) . '>';

        $filters = FilterChain::filters([new Name('ASCIIHexDecode'), new Name('FlateDecode')]);
        $parms   = FilterChain::parms([null, Dictionary::of(['Predictor' => 12, 'Columns' => 3])], 2);
        self::assertSame(['ASCIIHexDecode', 'FlateDecode'], $filters);
        self::assertSame($raw, FilterChain::decode($data, $filters, $parms));
        self::assertSame(['FlateDecode'], FilterChain::filters(new Name('FlateDecode')));
        self::assertTrue(FilterChain::endsInImageCodec(['FlateDecode', 'DCTDecode']));
        self::assertFalse(FilterChain::endsInImageCodec(['FlateDecode']));
        unset($encoded);
    }

    #[Test]
    public function decodesAscii85(): void
    {
        self::assertSame('Man ', Ascii85::decode('9jqo^'));
        self::assertSame('Man sure.', Ascii85::decode('<~9jqo^F*2M7/c~>'));
        self::assertSame("\0\0\0\0", Ascii85::decode('z'));
        self::assertSame('Hello', Ascii85::decode('87cURDZ~>'));
    }

    #[Test]
    public function decodesAsciiHex(): void
    {
        self::assertSame("\x01\xAB\xF0", AsciiHex::decode("01 ab\nF>"));
        self::assertSame("\x01\xAB", AsciiHex::decode('01AB'));
    }

    #[Test]
    public function decodesLzw(): void
    {
        // The example from the PDF specification: codes 45 45 45 65 ... encoding "-----A---B".
        $encoded = "\x80\x0B\x60\x50\x22\x0C\x0C\x85\x01";
        self::assertSame('-----A---B', Lzw::decode($encoded));
    }

    #[Test]
    public function decodesRunLength(): void
    {
        // literal run of 3, then 'x' repeated 4 times, then EOD
        self::assertSame('abcxxxx', RunLength::decode("\x02abc\xFDx\x80ignored"));
    }

    #[Test]
    public function undoesPredictors(): void
    {
        // PNG "Up" predictor on two 3-byte rows.
        $rows = "\x02\x01\x02\x03\x02\x01\x01\x01";
        self::assertSame("\x01\x02\x03\x02\x03\x04", Predictor::decode($rows, 12, 1, 8, 3));
        // TIFF predictor with 2 colours per pixel.
        self::assertSame("\x01\x02\x03\x05", Predictor::decode("\x01\x02\x02\x03", 2, 2, 8, 2));
    }
}
