<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use function count;
use function implode;
use function mb_convert_encoding;
use function mb_strlen;
use function pack;
use function strlen;

/**
 * Adobe Swatch Exchange data for tests: colour swatches (model, values and kind: 0 global,
 * 1 spot, 2 process) and colour groups, written as InDesign and Illustrator write them.
 */
final class AseBuilder
{
    /** @var list<string> */
    private array $blocks = [];

    private static function name(string $name): string
    {
        return (
            pack('n', mb_strlen($name) + 1)
                . mb_convert_encoding($name, to_encoding: 'UTF-16BE', from_encoding: 'UTF-8')
                . "\0\0"
        );
    }

    /** @param list<float> $values */
    public function colour(string $name, string $model, array $values, int $kind = 1): self
    {
        $body = self::name($name) . $model;
        foreach ($values as $value) {
            $body .= pack('G', $value);
        }
        $this->blocks[] = pack('nN', 0x0001, strlen($body . pack('n', $kind))) . $body . pack('n', $kind);

        return $this;
    }

    public function groupEnd(): self
    {
        $this->blocks[] = pack('nN', 0xC002, 0);

        return $this;
    }

    public function groupStart(string $name): self
    {
        $body           = self::name($name);
        $this->blocks[] = pack('nN', 0xC001, strlen($body)) . $body;

        return $this;
    }

    public function toString(): string
    {
        return 'ASEF' . pack('nnN', 1, 0, count($this->blocks)) . implode('', $this->blocks);
    }
}
