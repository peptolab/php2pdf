<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;

use function file_get_contents;
use function md5;
use function pathinfo;
use function sprintf;
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
        $data = Silencer::run(static fn(): string|false => file_get_contents($path));
        if (false === $data) {
            throw new IoException(sprintf('Cannot read ICC profile "%s".', $path));
        }

        return new self($data, $identifier ?? pathinfo($path, flags: PATHINFO_FILENAME));
    }

    /** A stable key for deduplicating the profile stream within a document. */
    public function key(): string
    {
        return md5($this->data);
    }
}
