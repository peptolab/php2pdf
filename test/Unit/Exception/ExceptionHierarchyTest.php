<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Exception;

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
    /** @return array<string, array{class-string, class-string}> */
    public static function exceptionProvider(): array
    {
        return [
            'a bad argument'           => [InvalidArgumentException::class, SplInvalidArgumentException::class],
            'a call the state refuses' => [StateException::class, LogicException::class],
            'a run-time failure'       => [PdfException::class, RuntimeException::class],
            'a file that cannot parse' => [ParseException::class, PdfException::class],
            'a file that cannot read'  => [IoException::class, PdfException::class],
            'a conformance failure'    => [ConformanceException::class, PdfException::class],
            'a layout failure'         => [LayoutException::class, PdfException::class],
        ];
    }

    /**
     * @param class-string<ExceptionInterface> $exception
     * @param class-string                     $spl
     */
    #[Test]
    #[DataProvider('exceptionProvider')]
    public function everyExceptionIsTheLibrarysAndOneOfPhps(string $exception, string $spl): void
    {
        $thrown = new $exception('x');

        self::assertSame([true, true], [$thrown instanceof ExceptionInterface, $thrown instanceof $spl]);
    }
}
