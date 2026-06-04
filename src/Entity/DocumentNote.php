<?php

namespace App\Entity;

use App\Repository\DocumentNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: DocumentNoteRepository::class)]
#[ORM\Table(name: 'document_note')]
#[ORM\Index(columns: ['xuid'], name: 'idx_document_note_xuid')]
class DocumentNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(length: 255)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Document $document, string $summary, string $content)
    {
        $this->xuid = Xuid::getXuid();
        $this->document = $document;
        $this->summary = $summary;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        $document->addNote($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getXuid(): string
    {
        return $this->xuid;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $summary;
        $this->touch();
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->touch();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'document_xuid' => $this->document->getXuid(),
            'summary' => $this->summary,
            'content' => $this->content,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
