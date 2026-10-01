<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Exception\PdfException;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function substr;

/**
 * Removes the content of layers a source file switches off by default from its content streams:
 * marked-content sections tagged /OC with a hidden group or membership dictionary, and forms
 * drawn with Do whose own /OC is hidden.
 *
 * @internal
 */
final class HiddenContent
{
    /** @var list<array{0: int, 1: int}> byte ranges to cut, in order */
    private array $cuts = [];

    /** Where the hidden section being skipped starts, or null outside one. */
    private ?int $skipFrom = null;

    /** Marked-content nesting inside the hidden section being skipped. */
    private int $depth = 0;

    /**
     * @param array<int, true> $off object numbers of the groups that are off by default
     */
    private function __construct(
        private readonly PdfReader $reader,
        private readonly array $off,
    ) {}

    /** The filter for a source file, or null when it switches no layer off. */
    public static function of(PdfReader $reader): ?self
    {
        $properties = $reader->dict($reader->catalog(), 'OCProperties');
        $config     = null === $properties ? null : $reader->dict($properties, 'D');
        if (null === $properties || null === $config) {
            return null;
        }
        $base = $reader->get($config, 'BaseState');
        $off  = $base instanceof Name && 'OFF' === $base->value
            ? self::notOn($reader, $properties, $config)
            : self::ids($reader->get($config, 'OFF'));

        return [] === $off ? null : new self($reader, array_fill_keys($off, value: true));
    }

    /** @param list<array{0: int, 1: int}> $cuts byte ranges, in order */
    private static function cut(string $content, array $cuts): string
    {
        if ([] === $cuts) {
            return $content;
        }
        $out  = '';
        $from = 0;
        foreach ($cuts as [$start, $end]) {
            $out  .= substr($content, $from, $start - $from) . ' ';
            $from = $end;
        }

        return $out . substr($content, $from);
    }

    /** @return list<int> object numbers of the references in a list */
    private static function ids(mixed $list): array
    {
        $ids = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (! $item instanceof Reference) {
                continue;
            }
            $ids[] = $item->id;
        }

        return $ids;
    }

    /**
     * The groups an OFF base state leaves off: every group not listed in /ON.
     *
     * @return list<int>
     */
    private static function notOn(PdfReader $reader, Dictionary $properties, Dictionary $config): array
    {
        $on = self::ids($reader->get($config, 'ON'));

        return array_values(array_filter(
            self::ids($reader->get($properties, 'OCGs')),
            static fn(int $id): bool => ! in_array($id, $on, strict: true),
        ));
    }

    /** The content stream with hidden layers cut out. */
    public function filter(string $content, ?Dictionary $resources): string
    {
        $lexer          = new Lexer($content);
        $parser         = new ObjectParser($lexer);
        $this->cuts     = [];
        $this->skipFrom = null;
        $this->depth    = 0;
        $operands       = [];
        $start          = null;
        while (true) {
            $before = $lexer->position();
            try {
                $token = $lexer->next();
                if ($token->is(Token::END)) {
                    break;
                }
                $value = $token->is(Token::KEYWORD) ? $token : $parser->parse($token);
            } catch (PdfException) {
                break;
            }
            if (! $value instanceof Token || ! $value->is(Token::KEYWORD)) {
                $start      ??= $before;
                $operands[] = $value;
                continue;
            }
            $operator = (string) $value->value;
            if ('BI' === $operator) {
                $this->skipInlineImage($lexer);
            }
            $this->operator($operator, $operands, $start ?? $before, $lexer->position(), $resources);
            $operands = [];
            $start    = null;
        }
        if (null !== $this->skipFrom) {
            $this->cuts[] = [$this->skipFrom, $lexer->length()];
        }

        return self::cut($content, $this->cuts);
    }

    /** Whether optional content (a group, or a membership dictionary over groups) is hidden. */
    public function isHidden(mixed $oc): bool
    {
        $dict = $this->reader->resolve($oc);
        if (! $dict instanceof Dictionary) {
            return false;
        }
        $type = $this->reader->get($dict, 'Type');
        if (! $type instanceof Name || 'OCMD' !== $type->value) {
            return $oc instanceof Reference && array_key_exists($oc->id, $this->off);
        }
        $groups = $dict->get('OCGs');
        $groups = $groups instanceof Reference ? [$groups] : $this->reader->resolve($groups);
        $states = [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            $states[] = ! ($group instanceof Reference && array_key_exists($group->id, $this->off));
        }
        if ([] === $states) {
            return false;
        }
        $policy = $this->reader->get($dict, 'P');
        $on     = count(array_filter($states));

        return ! match ($policy instanceof Name ? $policy->value : 'AnyOn') {
            'AllOn'  => count($states) === $on,
            'AnyOff' => $on < count($states),
            'AllOff' => 0 === $on,
            default  => $on > 0,
        };
    }

    private function hiddenForm(mixed $name, ?Dictionary $resources): bool
    {
        if (! $name instanceof Name || null === $resources) {
            return false;
        }
        $forms = $this->reader->dict($resources, 'XObject');
        $form  = null === $forms ? null : $this->reader->dict($forms, $name->value);

        return null !== $form && $form->has('OC') && $this->isHidden($form->get('OC'));
    }

    /** @param list<mixed> $operands the operands of a BDC operator */
    private function hiddenSection(array $operands, ?Dictionary $resources): bool
    {
        $tag      = $operands[0] ?? null;
        $property = $operands[1] ?? null;
        if (! $tag instanceof Name || 'OC' !== $tag->value) {
            return false;
        }
        if ($property instanceof Name) {
            $properties = null === $resources ? null : $this->reader->dict($resources, 'Properties');
            $property   = $properties?->get($property->value);
        }

        return null !== $property && ! is_string($property) && $this->isHidden($property);
    }

    /**
     * Acts on one operator spanning bytes $from to $to with its operands: opens a hidden section,
     * closes one, or cuts a hidden form.
     *
     * @param list<mixed> $operands
     */
    private function operator(string $operator, array $operands, int $from, int $to, ?Dictionary $resources): void
    {
        if (null !== $this->skipFrom) {
            $this->depth += match ($operator) {
                'BDC', 'BMC' => 1,
                'EMC'        => -1,
                default      => 0,
            };
            if (0 === $this->depth) {
                $this->cuts[]   = [$this->skipFrom, $to];
                $this->skipFrom = null;
            }

            return;
        }
        if ('BDC' === $operator && $this->hiddenSection($operands, $resources)) {
            $this->skipFrom = $from;
            $this->depth    = 1;

            return;
        }
        if ('Do' === $operator && $this->hiddenForm($operands[0] ?? null, $resources)) {
            $this->cuts[] = [$from, $to];
        }
    }

    /** Reads an inline image's dictionary up to ID, then skips its data. */
    private function skipInlineImage(Lexer $lexer): void
    {
        do {
            $token = $lexer->next();
        } while (! $token->is(Token::END) && ! $token->is(Token::KEYWORD, 'ID'));
        $lexer->skipInlineImageData();
    }
}
