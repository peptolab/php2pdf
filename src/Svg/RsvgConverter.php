<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Override;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Import\ImportedPage;
use Php2Pdf\Import\PdfFile;

use function escapeshellarg;
use function fclose;
use function fwrite;
use function is_resource;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;

/**
 * Converts SVG with the rsvg-convert command (librsvg), when it is installed, and places the
 * resulting PDF page through the importer: full SVG fidelity including filters and masks.
 *
 * @api
 */
final class RsvgConverter implements SvgConverterInterface
{
    public function __construct(
        private readonly string $binary = 'rsvg-convert',
    ) {}

    #[Override]
    public function convert(SvgFile $svg, Document $document): ImportedPage
    {
        // A file is converted by path so relative image references resolve; data comes via stdin.
        $arguments = ['--format=pdf', '--keep-aspect-ratio', $svg->path ?? '-'];
        $pdf       = $this->run($arguments, null === $svg->path ? $svg->data : '');

        return $document->import(PdfFile::fromData($pdf))->page(1);
    }

    /** Whether the converter can be run. */
    public function isAvailable(): bool
    {
        try {
            $this->run(['--version'], '');
        } catch (PdfException) {
            return false;
        }

        return true;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, string $input): string
    {
        $command = escapeshellarg($this->binary);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $pipes   = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new PdfException(sprintf('Cannot run "%s".', $this->binary));
        }
        $stdin  = $pipes[0] ?? null;
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (! is_resource($stdin) || ! is_resource($stdout) || ! is_resource($stderr)) {
            proc_close($process);
            throw new PdfException(sprintf('Cannot talk to "%s".', $this->binary));
        }
        fwrite($stdin, $input);
        fclose($stdin);
        $output = stream_get_contents($stdout);
        $error  = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $status = proc_close($process);
        if (0 !== $status || false === $output) {
            throw new PdfException(sprintf(
                '%s failed (%d): %s',
                $this->binary,
                $status,
                false === $error ? '' : $error,
            ));
        }

        return $output;
    }
}
