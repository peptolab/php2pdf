<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use DateTimeImmutable;
use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\TextString;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\InvalidArgumentException;

use function md5;
use function sprintf;
use function strcmp;
use function strlen;
use function usort;

/**
 * Files attached to the document: each an embedded file stream with its type, size, dates and
 * checksum, and a file specification with its relationship to the document, listed in the
 * catalog's /EmbeddedFiles name tree and its /AF array, as PDF/A-3 has them.
 *
 * @internal
 */
final class Attachments implements CatalogContributorInterface
{
    /** @var list<array{0: string, 1: Reference}> each file's name, as the name tree keys it, and specification */
    private array $files = [];

    public function __construct(
        private readonly Objects $objects,
        private readonly DocumentOptions $options,
        private readonly DateTimeImmutable $date,
    ) {}

    /**
     * Two files in the order of their names' bytes, as a name tree sorts its keys.
     *
     * @param array{0: string, 1: Reference} $a
     * @param array{0: string, 1: Reference} $b
     */
    private static function byName(array $a, array $b): int
    {
        return strcmp($a[0], $b[0]);
    }

    /** The catalog's name dictionary so far, or a new one. */
    private static function dictionary(mixed $names): Dictionary
    {
        return $names instanceof Dictionary ? $names : new Dictionary();
    }

    /**
     * Attaches a file under a name no other attached file has.
     *
     * @throws ConformanceException under PDF/X, or PDF/A-2, which allows only PDF/A files the
     *                              library cannot check
     * @throws InvalidArgumentException when a file of the same name is attached
     */
    public function attach(
        string $name,
        string $contents,
        string $mimeType,
        AttachmentRelationship $relationship,
        ?string $description,
    ): void {
        $key = TextString::encode($name);
        foreach ($this->files as [$attached]) {
            if ($attached === $key) {
                throw new InvalidArgumentException(sprintf('A file named "%s" is already attached.', $name));
            }
        }
        $refused = match (true) {
            null !== $this->options->pdfx => "{$this->options->pdfx->value} does not allow attachments.",
            PdfA::A2b === $this->options->pdfa => 'PDF/A-2b allows only PDF/A files to be attached, which the library '
                . 'cannot check; write PDF/A-3b to attach files of any kind.',
            default => null,
        };
        if (null !== $refused) {
            throw new ConformanceException($refused);
        }
        $date   = TextString::date($this->date);
        $stream = $this->objects->add(
            new Stream(Dictionary::of([
                'Type'    => new Name('EmbeddedFile'),
                'Subtype' => new Name($mimeType),
                'Params'  => Dictionary::of([
                    'Size'         => strlen($contents),
                    'CreationDate' => $date,
                    'ModDate'      => $date,
                    'CheckSum'     => md5($contents, binary: true),
                ]),
            ]), $contents),
        );
        $spec = $this->objects->add(Dictionary::of([
            'Type'           => new Name('Filespec'),
            'F'              => TextString::encode($name),
            'UF'             => TextString::encode($name),
            'Desc'           => null === $description ? null : TextString::encode($description),
            'AFRelationship' => new Name($relationship->value),
            'EF'             => Dictionary::of(['F' => $stream, 'UF' => $stream]),
        ]));
        $this->files[] = [$key, $spec];
    }

    /** Lists the attachments, of which there is at least one, in the catalog's name dictionary and its /AF array. */
    #[Override]
    public function contribute(Dictionary $catalog, array $pageRefs): void
    {
        $sorted = $this->files;
        usort($sorted, self::byName(...));
        $entries = [];
        $af      = [];
        foreach ($sorted as [$key, $spec]) {
            $entries[] = $key;
            $entries[] = $spec;
            $af[]      = $spec;
        }
        $names = self::dictionary($catalog->get('Names'));
        $names->set('EmbeddedFiles', $this->objects->add(Dictionary::of(['Names' => $entries])));
        $catalog->set('Names', $names);
        $catalog->set('AF', $af);
    }
}
