<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Exception\ParseException;

use function substr;

/**
 * Takes the MCIDs out of a content stream: each marked-content sequence with one in its inline
 * property list keeps its tag but loses its properties, so the content claims no element of a
 * structure tree. For an imported page drawn where its structure is not copied.
 *
 * @internal
 */
final readonly class McidRemover
{
    /** @throws ParseException for an inline image that cannot be read */
    public static function remove(string $content, int $maxNesting): string
    {
        $out  = '';
        $from = 0;
        foreach ((new ContentStreamParser($content, $maxNesting))->operations() as $operation) {
            [$tag, $properties] = [$operation->operands[0] ?? null, $operation->operands[1] ?? null];
            if (
                'BDC' !== $operation->operator
                || ! $tag instanceof Name
                || ! $properties instanceof Dictionary
                || ! $properties->has('MCID')
            ) {
                continue;
            }
            $out  .= substr($content, $from, $operation->start - $from) . " /{$tag->value} BMC";
            $from = $operation->end;
        }

        return $out . substr($content, $from);
    }
}
