<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Limits;
use Php2Pdf\Import\PdfReader;
use Php2PdfTest\TestAsset\HostileFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PageTreeTest extends TestCase
{
    #[Test]
    public function aPageInheritsWhatItDoesNotSetItself(): void
    {
        $reader = PdfReader::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 100] /Rotate 90 >>',
            '<< /Type /Page /Parent 2 0 R /Rotate 180 >>',
        ]));
        $page = $reader->pages()[0] ?? self::fail('The tree has no page.');

        self::assertEquals([[0, 0, 200, 100], 180], [$page->get('MediaBox'), $page->get('Rotate')]);
    }

    #[Test]
    public function aTreeDeeperThanTheNestingLimitIsNotWalkedFurther(): void
    {
        $reader = PdfReader::fromData(
            HostileFiles::pdf([
                '<< /Type /Catalog /Pages 2 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
                '<< /Type /Pages /Kids [5 0 R] /Count 1 >>',
                '<< /Type /Page /MediaBox [0 0 10 10] >>',
            ]),
            new Limits(maxObjectNesting: 2),
        );

        self::assertSame([], $reader->pages());
    }
}
