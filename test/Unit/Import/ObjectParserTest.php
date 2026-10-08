<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Import\Lexer;
use Php2Pdf\Import\ObjectParser;
use Php2Pdf\Import\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[Group('unit')]
final class ObjectParserTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float}> */
    public static function textualNumberProvider(): array
    {
        return [
            'numeric text'     => ['2.5', 2.5],
            'non-numeric text' => ['x', 0.0],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function tooDeepProvider(): array
    {
        return [
            'arrays'                 => [str_repeat('[', times: 4) . str_repeat(']', times: 4)],
            'dictionaries'           => [str_repeat('<< /K ', times: 4) . str_repeat('>> ', times: 4)],
            'arrays in dictionaries' => ['<< /A [<< /B [1] >>] >>'],
            'unclosed arrays'        => [str_repeat('[', times: 200_000)],
        ];
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function dictionary(mixed $value): Dictionary
    {
        self::assertInstanceOf(Dictionary::class, $value);

        return $value;
    }

    private static function parse(string $syntax): mixed
    {
        $parser = new ObjectParser(new Lexer($syntax));

        return $parser->parse();
    }

    private static function stream(mixed $value): Stream
    {
        self::assertInstanceOf(Stream::class, $value);

        return $value;
    }

    #[Test]
    public function anEmptyIndirectObjectIsNull(): void
    {
        self::assertSame([3, 0, null], (new ObjectParser(new Lexer('3 0 obj endobj')))->parseIndirect());
    }

    #[Test]
    #[DataProvider('textualNumberProvider')]
    public function aNumberTokenHoldingTextIsReadAsAReal(string $text, float $expected): void
    {
        self::assertSame($expected, (new ObjectParser(new Lexer('')))->parse(new Token(Token::NUMBER, $text, end: 0)));
    }

    #[Test]
    #[DataProvider('tooDeepProvider')]
    public function containersNestedPastTheLimitAreRejected(string $syntax): void
    {
        $parser = new ObjectParser(new Lexer($syntax), maxNesting: 3);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('PDF arrays and dictionaries are nested more than 3 deep');
        $parser->parse();
    }

    #[Test]
    public function containersNestedToTheLimitAreParsed(): void
    {
        $parser = new ObjectParser(new Lexer('[[[1]]]'), maxNesting: 3);

        self::assertSame([[[1]]], $parser->parse());
    }

    #[Test]
    public function parsesContainersAndReferences(): void
    {
        $value = self::array(self::parse('[1 2 0 R /X (s) [3] << /K 4 0 R >> 5]'));
        self::assertSame(1, $value[0] ?? null);
        self::assertEquals(new Reference(2, 0), $value[1] ?? null);
        self::assertEquals(new Name('X'), $value[2] ?? null);
        self::assertSame('s', $value[3] ?? null);
        self::assertSame([3], $value[4] ?? null);
        self::assertEquals(new Reference(4, 0), self::dictionary($value[5] ?? null)->get('K'));
        self::assertSame(5, $value[6] ?? null);
        self::assertSame(7, self::parse('7 8')); // "7 8" is not a reference
    }

    #[Test]
    public function parsesIndirectObjectsAndSkipsComments(): void
    {
        $parser   = new ObjectParser(new Lexer("% comment\n12 0 obj % another\n<< /Type /Page >>\nendobj"));
        $indirect = $parser->parseIndirect();

        self::assertSame([12, 0], [$indirect[0], $indirect[1]]);
        self::assertEquals(new Name('Page'), self::dictionary($indirect[2])->get('Type'));
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
        self::assertEquals(new Name('A B'), self::parse('/A#20B'));
    }

    #[Test]
    public function parsesStreamsWithGoodAndBadLengths(): void
    {
        $good = self::stream(self::parse("<< /Length 5 >>\nstream\nhello\nendstream"));
        self::assertSame('hello', $good->data);
        self::assertFalse($good->dictionary->has('Length'));

        $bad = self::stream(self::parse("<< /Length 99 >>\r\nstream\r\nhello\r\nendstream"));
        self::assertSame('hello', $bad->data);

        $resolving = new ObjectParser(
            new Lexer("<< /Length 9 0 R >>\nstream\nabc\nendstream"),
            static fn(): int => 3,
        );
        $indirect = self::stream($resolving->parse());
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
    public function theParserRecoversItsDepthAfterRejectingAnObject(): void
    {
        $lexer  = new Lexer('[[[[1]]]] [[[2]]]');
        $parser = new ObjectParser($lexer, maxNesting: 3);
        try {
            $parser->parse();
            self::fail('The first object nests too deep.');
        } catch (ParseException) {
            $lexer->seek(10);
        }

        self::assertSame([[[2]]], $parser->parse());
    }

    #[Test]
    public function toleratesBrokenDictionaries(): void
    {
        $dict = self::dictionary(self::parse('<< /A 1 2 /B 3 >>'));
        self::assertSame(1, $dict->get('A'));
        self::assertSame(3, $dict->get('B'));

        self::assertSame([1, 2], self::parse('[1 2 endobj'));

        $lexer = new Lexer('endobj');
        self::assertTrue($lexer->next()->is(Token::KEYWORD, 'endobj'));
        self::assertTrue($lexer->next()->is(Token::END));
    }
}
