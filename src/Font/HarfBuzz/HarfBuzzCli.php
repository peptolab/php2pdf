<?php

declare(strict_types=1);

namespace Php2Pdf\Font\HarfBuzz;

use Override;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\ExternalShaperInterface;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use WeakMap;

use function fclose;
use function file_put_contents;
use function implode;
use function is_resource;
use function preg_match_all;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function trim;

use const PREG_SET_ORDER;

/**
 * HarfBuzz through its hb-shape command, for where FFI is not available: a process per shaped
 * run (the font engine caches shaped text), each font written once to a temporary file that is
 * removed when the font or the shaper goes.
 *
 * @api
 */
final class HarfBuzzCli implements ExternalShaperInterface
{
    /** @var WeakMap<OpenTypeFont, TemporaryFile> */
    private WeakMap $files;

    /** @param string $binary the hb-shape command: a path, or a name found on the PATH */
    public function __construct(
        private readonly string $binary = 'hb-shape',
    ) {
        $this->files = new WeakMap();
    }

    /** @throws IoException */
    #[Override]
    public function shape(
        OpenTypeFont $font,
        string $text,
        string $script,
        TextDirection $direction,
        array $features,
        ?string $language = null,
    ): array {
        $command = [
            $this->binary,
            "--font-file={$this->file($font)}",
            "--face-index={$font->faceIndex}",
            '--no-glyph-names',
            '--utf8-clusters',
            '--output-format=json',
            "--script={$script}",
            '--direction=' . (TextDirection::RightToLeft === $direction ? 'rtl' : 'ltr'),
            "--text={$text}",
        ];
        if (null !== $language) {
            $command[] = "--language={$language}";
        }
        if ([] !== $features) {
            $command[] = '--features=' . implode(',', $features);
        }
        $matches = [];
        preg_match_all(
            '/\{"g":(\d+),"cl":(\d+),"dx":(-?\d+),"dy":(-?\d+),"ax":(-?\d+),"ay":(-?\d+)\}/',
            $this->run($command),
            $matches,
            PREG_SET_ORDER,
        );
        $glyphs = [];
        foreach ($matches as $m) {
            $glyphs[] = [
                (int) ($m[1] ?? 0),
                (int) ($m[2] ?? 0),
                (int) ($m[5] ?? 0),
                (int) ($m[6] ?? 0),
                (int) ($m[3] ?? 0),
                (int) ($m[4] ?? 0),
            ];
        }

        return $glyphs;
    }

    /**
     * The font's data in a temporary file, written the first time it is shaped.
     *
     * @throws IoException
     */
    private function file(OpenTypeFont $font): string
    {
        $file = $this->files[$font] ?? null;
        if (null !== $file) {
            return $file->path;
        }
        $path = tempnam(sys_get_temp_dir(), prefix: 'php2pdf-hb-');
        if (false === $path) {
            throw new IoException('Cannot write a temporary font file for hb-shape.');
        }
        $file = new TemporaryFile($path);
        if (false === file_put_contents($path, $font->data())) {
            throw new IoException('Cannot write a temporary font file for hb-shape.');
        }
        $this->files[$font] = $file;

        return $path;
    }

    /**
     * Runs hb-shape without a shell and returns what it printed.
     *
     * @param list<string> $command
     *
     * @throws PdfException when it cannot be run or fails
     */
    private function run(array $command): string
    {
        /** @var array<int, resource> $pipes */
        $pipes   = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new IoException(sprintf('Cannot run "%s".', $this->binary));
        }
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (! is_resource($stdout) || ! is_resource($stderr)) {
            proc_close($process);

            throw new IoException(sprintf('Cannot read from "%s".', $this->binary));
        }
        $output = (string) stream_get_contents($stdout);
        $errors = (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        if (0 !== proc_close($process)) {
            throw new IoException(sprintf('"%s" failed: %s', $this->binary, trim($errors)));
        }

        return $output;
    }
}
