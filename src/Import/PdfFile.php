<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function count;
use function is_string;
use function mb_convert_encoding;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * An existing PDF opened for reading: page count, page geometry and document information. Pass
 * it to Document::import() to place its pages as templates.
 *
 * @api
 */
final class PdfFile
{
    /** @var list<PdfPage>|null */
    private ?array $pages = null;

    private function __construct(
        private readonly PdfReader $reader,
        public readonly ?string $path,
    ) {}

    /** @throws PdfException when the data is not a readable PDF or breaks a limit */
    public static function fromData(string $data, ?Limits $limits = null): self
    {
        return new self(PdfReader::fromData($data, $limits), null);
    }

    /** @throws PdfException when the file is not a readable PDF or breaks a limit */
    public static function open(string $path, ?Limits $limits = null): self
    {
        return new self(PdfReader::fromFile($path, $limits), $path);
    }

    private static function textToUtf8(string $text): string
    {
        if (str_starts_with($text, "\xFE\xFF")) {
            $converted = mb_convert_encoding(substr($text, offset: 2), to_encoding: 'UTF-8', from_encoding: 'UTF-16BE');

            return is_string($converted) ? $converted : '';
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, offset: 3);
        }
        // PDFDocEncoding is Latin-1 for the characters that matter here.
        $converted = mb_convert_encoding($text, to_encoding: 'UTF-8', from_encoding: 'ISO-8859-1');

        return is_string($converted) ? $converted : $text;
    }

    /**
     * Document information (Title, Author, Subject, Keywords, Creator, Producer...) as UTF-8.
     *
     * @return array<string, string>
     *
     * @throws ParseException
     */
    public function info(): array
    {
        $info = $this->reader->dict($this->reader->trailer(), 'Info');
        $out  = [];
        if (null === $info) {
            return $out;
        }
        foreach ($info->all() as $key => $value) {
            $value = $this->reader->resolve($value);
            if (is_string($value)) {
                $out[$key] = self::textToUtf8($value);
            }
        }

        return $out;
    }

    /**
     * A page by 1-based number.
     *
     * @throws ParseException
     */
    public function page(int $number): PdfPage
    {
        return $this->pages()[$number - 1]
            ?? throw new ParseException(sprintf(
                'Page %d does not exist; the file has %d pages.',
                $number,
                $this->pageCount(),
            ));
    }

    /** @throws ParseException */
    public function pageCount(): int
    {
        return count($this->pages());
    }

    /** @internal */
    public function reader(): PdfReader
    {
        return $this->reader;
    }

    public function version(): string
    {
        return $this->reader->version();
    }

    /**
     * @return list<PdfPage>
     *
     * @throws ParseException
     */
    private function pages(): array
    {
        if (null === $this->pages) {
            $this->pages = [];
            foreach ($this->reader->pages() as $i => $dict) {
                $this->pages[] = new PdfPage($this->reader, $dict, $i + 1);
            }
        }

        return $this->pages;
    }
}
