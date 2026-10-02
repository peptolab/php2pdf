<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Exception;

use Exception;
use InvalidArgumentException as SplInvalidArgumentException;
use LogicException;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Exception\StateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
final class ExceptionHierarchyTest extends TestCase
{
    /** @return array<string, array{Exception, class-string}> */
    public static function exceptionProvider(): array
    {
        return [
            'a bad argument'           => [new InvalidArgumentException('x'), SplInvalidArgumentException::class],
            'a call the state refuses' => [new StateException('x'), LogicException::class],
            'a run-time failure'       => [new PdfException('x'), RuntimeException::class],
            'a file that cannot parse' => [new ParseException('x'), PdfException::class],
            'a file that cannot read'  => [new IoException('x'), PdfException::class],
            'a conformance failure'    => [new ConformanceException('x'), PdfException::class],
            'a layout failure'         => [new LayoutException('x'), PdfException::class],
        ];
    }

    /** @param class-string $spl */
    #[Test]
    #[DataProvider('exceptionProvider')]
    public function everyExceptionIsTheLibrarysAndOneOfPhps(Exception $thrown, string $spl): void
    {
        self::assertSame([true, true], [$thrown instanceof ExceptionInterface, $thrown instanceof $spl]);
    }
}
