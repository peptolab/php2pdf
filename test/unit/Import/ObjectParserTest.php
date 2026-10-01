<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Import;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Import\Lexer;
use PhpPdf\Import\ObjectParser;
use PhpPdf\Import\Token;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObjectParserTest extends TestCase
{
    private static function parse(string $syntax): mixed
    {
        $parser = new ObjectParser(new Lexer($syntax));

        return $parser->parse();
    }

    #[Test]
    public function parsesContainersAndReferences(): void
    {
        $value = self::parse('[1 2 0 R /X (s) [3] << /K 4 0 R >> 5]');
        self::assertIsArray($value);
        self::assertSame(1, $value[0]);
        self::assertEquals(new Reference(2, 0), $value[1]);
        self::assertEquals(new Name('X'), $value[2]);
        self::assertSame('s', $value[3]);
        self::assertSame([3], $value[4]);
        self::assertInstanceOf(Dictionary::class, $value[5]);
        self::assertEquals(new Reference(4, 0), $value[5]->get('K'));
        self::assertSame(5, $value[6]);
        self::assertSame(7, self::parse('7 8')); // "7 8" is not a reference
    }

    #[Test]
    public function parsesIndirectObjectsAndSkipsComments(): void
    {
        $parser = new ObjectParser(new Lexer("% comment\n12 0 obj % another\n<< /Type /Page >>\nendobj"));
        [$number, $generation, $object] = $parser->parseIndirect();

        self::assertSame([12, 0], [$number, $generation]);
        self::assertInstanceOf(Dictionary::class, $object);
        self::assertEquals(new Name('Page'), $object->get('Type'));
    }

    #[Test]
    public function parsesScalarsAndNames(): void
    {
        self::assertSame(42, self::parse('42'));
        self::assertSame(-3.5, self::parse('-3.5'));
        self::assertSame(0.5, self::parse('.5'));
        self::assertSame(-5.0, self::parse('--5'));
        self::assertTrue(self::parse('true'));
        self::assertNull(self::parse('null'));
        $name = self::parse('/A#20B');
        self::assertInstanceOf(Name::class, $name);
        self::assertSame('A B', $name->value);
    }

    #[Test]
    public function parsesStreamsWithGoodAndBadLengths(): void
    {
        $good = self::parse("<< /Length 5 >>\nstream\nhello\nendstream");
        self::assertInstanceOf(Stream::class, $good);
        self::assertSame('hello', $good->data);
        self::assertFalse($good->dictionary->has('Length'));

        $bad = self::parse("<< /Length 99 >>\r\nstream\r\nhello\r\nendstream");
        self::assertInstanceOf(Stream::class, $bad);
        self::assertSame('hello', $bad->data);

        $resolving = new ObjectParser(
            new Lexer("<< /Length 9 0 R >>\nstream\nabc\nendstream"),
            static fn(mixed $v): mixed => 3,
        );
        $indirect = $resolving->parse();
        self::assertInstanceOf(Stream::class, $indirect);
        self::assertSame('abc', $indirect->data);
    }

    #[Test]
    public function parsesStrings(): void
    {
        self::assertSame('a(b)c', self::parse('(a(b)c)'));
        self::assertSame("line\nbreak\\", self::parse('(line\nbreak\\\\)'));
        self::assertSame("A\x08", self::parse('(\101\b)'));
        self::assertSame('joined', self::parse("(join\\\ned)"));
        self::assertSame("cr\nlf", self::parse("(cr\r\nlf)"));
        self::assertSame("\x90\x1F\xA0", self::parse('<90 1F A>'));
    }

    #[Test]
    public function toleratesBrokenDictionaries(): void
    {
        $dict = self::parse('<< /A 1 2 /B 3 >>');
        self::assertInstanceOf(Dictionary::class, $dict);
        self::assertSame(1, $dict->get('A'));
        self::assertSame(3, $dict->get('B'));

        $array = self::parse('[1 2 endobj');
        self::assertSame([1, 2], $array);

        $lexer = new Lexer('endobj');
        self::assertTrue($lexer->next()->is(Token::KEYWORD, 'endobj'));
        self::assertTrue($lexer->next()->is(Token::END));
    }
}
