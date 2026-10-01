<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\IoException;

/**
 * An ICC profile loaded from disk or memory. The component count is read from the profile header.
 *
 * @api
 */
final readonly class IccProfile
{
    public int $componentCount;

    public function __construct(public string $data, public string|null $identifier = null)
    {
        if (strlen($data) < 20) {
            throw new InvalidArgumentException('ICC profile data is too short.');
        }
        $this->componentCount = match (substr($data, 16, 4)) {
            'GRAY' => 1,
            'RGB ', 'Lab ', 'XYZ ' => 3,
            'CMYK' => 4,
            default => throw new InvalidArgumentException('Unsupported ICC colour space in profile.'),
        };
    }

    public static function fromFile(string $path, string|null $identifier = null): self
    {
        $data = @file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read ICC profile "%s".', $path));
        }

        return new self($data, $identifier ?? pathinfo($path, PATHINFO_FILENAME));
    }

    /** A stable key for deduplicating the profile stream within a document. */
    public function key(): string
    {
        return md5($this->data);
    }
}
