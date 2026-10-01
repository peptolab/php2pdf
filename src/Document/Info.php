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
    private ?string $title    = null;
    private ?string $author   = null;
    private ?string $subject  = null;
    private ?string $keywords = null;
    private ?string $creator  = null;

    public function author(): ?string
    {
        return $this->author;
    }

    public function creator(): ?string
    {
        return $this->creator;
    }

    public function keywords(): ?string
    {
        return $this->keywords;
    }

    public function setAuthor(?string $author): self
    {
        $this->author = $author;

        return $this;
    }

    /** The application that created the original content (not the PDF producer). */
    public function setCreator(?string $creator): self
    {
        $this->creator = $creator;

        return $this;
    }

    public function setKeywords(?string $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function title(): ?string
    {
        return $this->title;
    }
}
