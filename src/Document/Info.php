<?php

declare(strict_types=1);

namespace PhpPdf\Document;

/**
 * Document information: title, author and friends. Written to the Info dictionary and XMP.
 *
 * @api
 */
final class Info
{
    private string|null $title = null;
    private string|null $author = null;
    private string|null $subject = null;
    private string|null $keywords = null;
    private string|null $creator = null;

    public function author(): string|null
    {
        return $this->author;
    }

    public function creator(): string|null
    {
        return $this->creator;
    }

    public function keywords(): string|null
    {
        return $this->keywords;
    }

    public function setAuthor(string|null $author): self
    {
        $this->author = $author;

        return $this;
    }

    /** The application that created the original content (not the PDF producer). */
    public function setCreator(string|null $creator): self
    {
        $this->creator = $creator;

        return $this;
    }

    public function setKeywords(string|null $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function setSubject(string|null $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function setTitle(string|null $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function subject(): string|null
    {
        return $this->subject;
    }

    public function title(): string|null
    {
        return $this->title;
    }
}
