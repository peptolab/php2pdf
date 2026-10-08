<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\FontFamily;
use Php2Pdf\Font\FontInfo;
use Php2Pdf\Font\FontStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A family whose only face is bold: the other styles are refused before any file is read. */
#[Group('unit')]
final class FontFamilyTest extends TestCase
{
    private FontInfo $bold;
    private FontFamily $family;

    #[Test]
    public function aMissingBoldItalicFaceIsRefused(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Family "Example" has no BoldItalic face.');

        $this->family->boldItalic();
    }

    #[Test]
    public function aMissingItalicFaceIsRefused(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Family "Example" has no Italic face.');

        $this->family->italic();
    }

    #[Test]
    public function theFamilyListsItsFaces(): void
    {
        self::assertSame([$this->bold], $this->family->faces());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->bold = new FontInfo(
            '/fonts/Example-Bold.ttf',
            0,
            'Example',
            'Bold',
            'Example Bold',
            'Example-Bold',
            FontStyle::Bold,
        );
        $this->family = new FontFamily((new Document(new MemoryOutput()))->fonts(), 'Example', [$this->bold]);
    }
}
