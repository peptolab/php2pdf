<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Core\FileReader;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;

use function hash;
use function pathinfo;
use function strlen;
use function substr;

use const PATHINFO_FILENAME;

/**
 * An ICC profile loaded from disk or memory. The component count is read from the profile header.
 *
 * @api
 */
final readonly class IccProfile
{
    public int $componentCount;

    public function __construct(
        public string $data,
        public ?string $identifier = null,
    ) {
        if (strlen($data) < 20) {
            throw new InvalidArgumentException('ICC profile data is too short.');
        }
        $this->componentCount = match (substr($data, offset: 16, length: 4)) {
            'GRAY'                 => 1,
            'RGB ', 'Lab ', 'XYZ ' => 3,
            'CMYK'                 => 4,
            default                => throw new InvalidArgumentException('Unsupported ICC colour space in profile.'),
        };
    }

    /** @throws IoException */
    public static function fromFile(string $path, ?string $identifier = null): self
    {
        return new self(
            FileReader::read($path, 'ICC profile'),
            $identifier ?? pathinfo($path, flags: PATHINFO_FILENAME),
        );
    }

    /** A stable key for deduplicating the profile stream within a document. */
    public function key(): string
    {
        return hash('xxh128', $this->data);
    }
}
