<?php

namespace App\Services\Ai;

use App\Contracts\ChatResponder;
use App\Support\ChatReply;
use App\Support\RetrievedChunk;

/**
 * Live ChatResponder: answers with Gemini, grounded ONLY in the retrieved chunks
 * (the backend has already restricted them to approved documents).
 *
 * Sources are numbered [1], [2], … per document; the model is told to cite them,
 * and sourcesReferenced is built from the numbers it actually cited. Citations
 * in the answer are then renumbered so [n] is the n-th entry of sourcesReferenced.
 */
class GeminiChatResponder implements ChatResponder
{
    public function __construct(protected GeminiClient $gemini) {}

    public function respond(string $message, array $history, array $context): ChatReply
    {
        [$sourceBlock, $numberToArtifact] = $this->numberSources($context);

        $answer = $this->gemini->generate(
            $this->systemPrompt($sourceBlock),
            $this->conversation($history, $message),
        );

        $cited = $this->citedArtifacts($answer, $numberToArtifact);

        return new ChatReply(
            content: $this->renumberCitations($answer, $numberToArtifact, $cited),
            sourcesReferenced: $cited,
        );
    }

    /**
     * The UI numbers sources by their position in sourcesReferenced (order of
     * first citation), but the model cites the numbers from the prompt. When it
     * cites [2] before [1], or skips a source, those differ, so rewrite the
     * answer's citations to match: the first source cited becomes [1], and so on.
     *
     * @param  array<int, string>  $numberToArtifact  prompt number => artifact id
     * @param  list<string>  $cited  artifact ids in order of first citation
     */
    protected function renumberCitations(string $answer, array $numberToArtifact, array $cited): string
    {
        $position = array_flip($cited);

        return preg_replace_callback('/\[(\d+(?:\s*,\s*\d+)*)\]/', function (array $m) use ($numberToArtifact, $position) {
            $numbers = preg_split('/\s*,\s*/', $m[1]);
            $mapped = [];

            foreach ($numbers as $n) {
                $id = $numberToArtifact[(int) $n] ?? null;
                if ($id === null || ! isset($position[$id])) {
                    return $m[0]; // not one of our sources: leave the text alone
                }
                $mapped[] = $position[$id] + 1;
            }

            return '['.implode(', ', $mapped).']';
        }, $answer) ?? $answer;
    }

    /**
     * @param  list<RetrievedChunk>  $context
     * @return array{0: string, 1: array<int, string>}
     */
    protected function numberSources(array $context): array
    {
        $byArtifact = [];
        foreach ($context as $chunk) {
            $byArtifact[$chunk->artifactId] ??= ['title' => $chunk->title, 'author' => $chunk->author, 'texts' => []];
            $byArtifact[$chunk->artifactId]['texts'][] = trim($chunk->text);
        }

        $blocks = [];
        $map = [];
        $n = 0;

        foreach ($byArtifact as $artifactId => $doc) {
            $n++;
            $map[$n] = (string) $artifactId;
            $heading = "[{$n}] \"".($doc['title'] ?: 'Untitled').'"'.($doc['author'] ? " by {$doc['author']}" : '');
            $blocks[] = $heading."\n".implode("\n…\n", array_filter($doc['texts']));
        }

        return [implode("\n\n", $blocks), $map];
    }

    protected function systemPrompt(string $sourceBlock): string
    {
        $sources = $sourceBlock !== ''
            ? "SOURCES:\n\n{$sourceBlock}"
            : 'SOURCES: (none matched this question)';

        return <<<PROMPT
        You are the research assistant for a university's digital repository of senior projects.
        Answer the student's question using ONLY the sources below, which are excerpts from approved projects in the repository.

        Rules:
        - Cite every claim with the source number in square brackets, e.g. [1] or [2][3].
        - If the sources don't contain the answer, say you couldn't find it in the repository and suggest how to rephrase. Do not use outside knowledge to fill gaps.
        - Refer to projects by their title (and author when given).
        - Be concise and clear. Use short paragraphs or bullet points.

        {$sources}
        PROMPT;
    }

    /**
     * Gemini wants alternating user/model turns that start with a user turn.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, text: string}>
     */
    protected function conversation(array $history, string $message): array
    {
        $turns = [];

        foreach ([...$history, ['role' => 'user', 'content' => $message]] as $entry) {
            $role = ($entry['role'] ?? 'user') === 'model' ? 'model' : 'user';
            $text = trim((string) ($entry['content'] ?? ''));

            if ($text === '' || ($turns === [] && $role === 'model')) {
                continue;
            }

            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['text'] .= "\n\n".$text; // merge consecutive same-role turns
            } else {
                $turns[] = ['role' => $role, 'text' => $text];
            }
        }

        return $turns;
    }

    /** @return list<string> */
    protected function citedArtifacts(string $answer, array $numberToArtifact): array
    {
        preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $answer, $matches);

        $ids = [];
        foreach ($matches[1] as $group) {
            foreach (preg_split('/\s*,\s*/', $group) as $n) {
                if (isset($numberToArtifact[(int) $n])) {
                    $ids[$numberToArtifact[(int) $n]] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
