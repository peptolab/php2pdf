<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SvgFileTest extends TestCase
{
    #[Test]
    public function aRootOtherThanSvgIsRejected(): void
    {
        $file = SvgFile::fromData('<html><svg/></html>');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Expected an <svg> root element, found <html>.');

        $file->root();
    }
}
