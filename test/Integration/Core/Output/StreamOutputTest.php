<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Core\Output;

use Override;
use Php2Pdf\Core\Output\StreamOutput;
use Php2Pdf\Exception\IoException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function is_resource;
use function rewind;
use function stream_get_contents;

#[Group('integration')]
final class StreamOutputTest extends TestCase
{
    /** @var resource|null */
    private mixed $stream = null;

    #[Test]
    public function countsTheBytesWritten(): void
    {
        $output = new StreamOutput($this->open('w+b'));
        $output->write('%PDF-');
        $output->write('1.7');

        self::assertSame(8, $output->position());
    }

    #[Test]
    public function leavesTheStreamOpenWithItsBytesWhenClosed(): void
    {
        $stream = $this->open('w+b');
        $output = new StreamOutput($stream);
        $output->write('%PDF-1.7');
        $output->close();
        rewind($stream);

        self::assertSame('%PDF-1.7', stream_get_contents($stream));
    }

    #[Test]
    public function refusesAClosedStream(): void
    {
        $stream = $this->open('w+b');
        fclose($stream);

        $this->expectException(IoException::class);
        $this->expectExceptionMessage('StreamOutput expects an open stream resource.');

        new StreamOutput($stream);
    }

    #[Test]
    public function reportsAStreamThatRefusesTheBytes(): void
    {
        $output = new StreamOutput($this->open('rb'));

        $this->expectException(IoException::class);
        $this->expectExceptionMessage('Short write to stream.');

        $output->write('%PDF-1.7');
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return resource */
    private function open(string $mode): mixed
    {
        $stream = fopen('php://memory', $mode);
        self::assertIsResource($stream);
        $this->stream = $stream;

        return $stream;
    }
}
