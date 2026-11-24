<?php

namespace App\Services\Ai;

use App\Contracts\DocumentIngestor;
use App\Models\Artifact;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Config as ParserConfig;
use Smalot\PdfParser\Parser;

/**
 * Live DocumentIngestor: PDF → text → overlapping chunks → Gemini embeddings → Qdrant.
 *
 * Every point carries Artifact::vectorMetadata() (incl. status / is_approved) plus
 * chunkIndex and text. Pending files are embedded too so admins can search them;
 * student searches filter on is_approved.
 *
 * Idempotent: the document's existing points are deleted before the new ones are
 * written, so a retry or a reprocess never leaves duplicate chunks.
 */
class GeminiDocumentIngestor implements DocumentIngestor
{
    public function __construct(
        protected GeminiClient $gemini,
        protected QdrantClient $qdrant,
    ) {}

    public function ingest(Artifact $artifact): int
    {
        $text = $this->extractText($artifact->fileContents());
        $chunks = TextChunker::fromConfig()->chunk($text);

        if ($chunks === []) {
            throw new RuntimeException('No readable text found in this PDF (it may be a scanned image).');
        }

        $vectors = $this->gemini->embedDocuments($chunks);
        $metadata = $artifact->vectorMetadata();

        $points = [];
        foreach ($chunks as $index => $chunk) {
            $points[] = [
                'id' => (string) Str::uuid(),
                'vector' => $vectors[$index],
                'payload' => $metadata + ['chunkIndex' => $index, 'text' => $chunk],
            ];
        }

        $this->qdrant->deleteArtifact((string) $artifact->id);
        $this->qdrant->upsert($points);

        return count($points);
    }

    /** The PDF's plain text (also used by the AI submission check). */
    public function textOf(Artifact $artifact): string
    {
        return $this->extractText($artifact->fileContents());
    }

    protected function extractText(string $pdfBytes): string
    {
        // Big scanned or image-heavy PDFs can exceed PHP's default 128 MB while
        // parsing. Images are never needed for text, so drop them, and allow more
        // memory just for this step.
        $config = new ParserConfig;
        $config->setRetainImageContent(false);

        $previousLimit = ini_get('memory_limit');
        $wanted = (string) config('services.chunking.pdf_memory_limit', '1024M');
        if ($this->bytes($previousLimit) !== -1 && $this->bytes($previousLimit) < $this->bytes($wanted)) {
            ini_set('memory_limit', $wanted);
        }

        try {
            return (new Parser([], $config))->parseContent($pdfBytes)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read the PDF: '.$e->getMessage(), previous: $e);
        } finally {
            ini_set('memory_limit', (string) $previousLimit);
        }
    }

    /** "128M" → bytes; -1 stays -1 (unlimited). */
    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
