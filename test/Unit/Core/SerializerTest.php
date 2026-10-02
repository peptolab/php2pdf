<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\HexString;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Raw;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Serializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SerializerTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function values(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [42, '42'];
        yield 'negative int' => [-7, '-7'];
        yield 'float' => [1.5, '1.5'];
        yield 'integral float' => [3.0, '3'];
        yield 'tiny float' => [0.000_01, '0'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'rounded float' => [1.234_567_89, '1.2346'];
        yield 'string' => ['Hello', '(Hello)'];
        yield 'escaped string' => ["a(b)c\\d\r\n", '(a\\(b\\)c\\\\d\\r\\n)'];
        yield 'name' => [new Name('Type'), '/Type'];
        yield 'escaped name' => [new Name('A B#/C'), '/A#20B#23#2FC'];
        yield 'reference' => [new Reference(12), '12 0 R'];
        yield 'hex string' => [new HexString("\x01\xAB"), '<01ab>'];
        yield 'raw' => [new Raw('1 0 0 1 0 0 cm'), '1 0 0 1 0 0 cm'];
        yield 'array' => [[1, 2.5, new Name('X'), 'y'], '[1 2.5 /X (y)]'];
        yield 'dictionary' => [
            Dictionary::of(['Type' => new Name('Page'), 'Count' => 3, 'Kids' => [new Reference(4)]]),
            '<</Type/Page/Count 3/Kids[4 0 R]>>',
        ];
        yield 'nested dictionary' => [
            Dictionary::of(['A' => Dictionary::of(['B' => true])]),
            '<</A<</B true>>>>',
        ];
    }

    #[Test]
    public function nullDictionaryEntriesAreDropped(): void
    {
        $dict = Dictionary::of(['A' => 1, 'B' => null]);

        self::assertFalse($dict->has('B'));
        self::assertSame('<</A 1>>', Serializer::serialize($dict));
    }

    #[Test]
    #[DataProvider('values')]
    public function serialisesValues(mixed $value, string $expected): void
    {
        self::assertSame($expected, Serializer::serialize($value));
    }
}
