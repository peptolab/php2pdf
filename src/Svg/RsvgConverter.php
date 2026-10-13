<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Override;
use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PermissionException;
use Php2Pdf\Import\ImportedPage;
use Php2Pdf\Import\PdfFile;

use function escapeshellarg;
use function fclose;
use function fwrite;
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

    /**
     * @throws IoException
     * @throws ParseException
     * @throws PermissionException when an encrypted file needs a password or forbids importing
     */
    #[Override]
    public function convert(SvgFile $svg, SvgDocumentInterface $document): ImportedPage
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
        } catch (ExceptionInterface) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws IoException
     */
    private function run(array $arguments, string $input): string
    {
        $command = escapeshellarg($this->binary);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $pipes = [];
        // The command runs through the shell, so the process and its pipes open even when the
        // binary is missing (the shell exits with 127); only a failing system stops them.
        /** @var resource $process */
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        [$stdin, $stdout, $stderr] = $pipes;
        fwrite($stdin, $input);
        fclose($stdin);
        $output = stream_get_contents($stdout);
        $error  = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $status = proc_close($process);
        if (0 !== $status || false === $output) {
            throw new IoException(sprintf(
                '%s failed (%d): %s',
                $this->binary,
                $status,
                false === $error ? '' : $error,
            ));
        }

        return $output;
    }
}
