<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Generator;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\ParseException;

/**
 * Walks a content stream operation by operation. The walk ends quietly at the first token it
 * cannot read, as viewers stop drawing there; an inline image that cannot be read is an error.
 *
 * @internal
 */
final readonly class ContentStreamParser
{
    public function __construct(
        private string $content,
        private int $maxNesting,
    ) {}

    /**
     * Reads an inline image's entries up to ID, then skips its data to EI.
     *
     * @return array<string, mixed>
     *
     * @throws ParseException
     */
    private static function inlineImage(Lexer $lexer, ObjectParser $parser): array
    {
        $entries = [];
        while (true) {
            $token = $lexer->next();
            if ($token->is(Token::END) || $token->is(Token::KEYWORD, 'ID')) {
                break;
            }
            $key = $parser->parse($token);
            if (! $key instanceof Name) {
                continue;
            }
            $value = $lexer->next();
            if ($value->is(Token::END) || $value->is(Token::KEYWORD, 'ID')) {
                break;
            }
            $entries[$key->value] = $parser->parse($value);
        }
        $lexer->skipInlineImageData();

        return $entries;
    }

    /**
     * @return Generator<int, ContentOperation>
     *
     * @throws ParseException for an inline image that cannot be read
     */
    public function operations(): Generator
    {
        $lexer    = new Lexer($this->content);
        $parser   = new ObjectParser($lexer, maxNesting: $this->maxNesting);
        $operands = [];
        $start    = null;
        while (true) {
            $before = $lexer->position();
            try {
                $token = $lexer->next();
                if ($token->is(Token::END)) {
                    return;
                }
                $value = $token->is(Token::KEYWORD) ? $token : $parser->parse($token);
            } catch (ExceptionInterface) {
                return;
            }
            if (! $value instanceof Token || ! $value->is(Token::KEYWORD)) {
                $start      ??= $before;
                $operands[] = $value;
                continue;
            }
            $operator = (string) $value->value;
            $image    = 'BI' === $operator ? self::inlineImage($lexer, $parser) : null;
            yield new ContentOperation($operator, $operands, $start ?? $before, $lexer->position(), $image);
            $operands = [];
            $start    = null;
        }
    }
}
