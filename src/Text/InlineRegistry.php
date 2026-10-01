<?php

declare(strict_types=1);

namespace PhpPdf\Text;

use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Exception\InvalidArgumentException;

use function array_key_exists;
use function sprintf;

/**
 * Artwork that Textflow markup sets in the text by name: <img src="name">.
 *
 * @api
 */
final class InlineRegistry
{
    /** @var array<string, PlaceableInterface> */
    private array $objects = [];

    public function define(string $name, PlaceableInterface $object): self
    {
        $this->objects[$name] = $object;

        return $this;
    }

    public function get(string $name): PlaceableInterface
    {
        return (
            $this->objects[$name] ?? throw new InvalidArgumentException(sprintf(
                'No inline object named "%s"; define it with Document::inlines()->define().',
                $name,
            ))
        );
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->objects);
    }
}
