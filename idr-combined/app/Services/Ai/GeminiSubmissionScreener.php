<?php

namespace App\Services\Ai;

use App\Contracts\SubmissionScreener;
use App\Models\Artifact;
use App\Support\ScreeningResult;

/**
 * Live SubmissionScreener: Gemini reads the opening pages of the PDF (title
 * page, abstract, contents) and says whether it looks like a senior design or
 * senior research project of the declared type. Anything unclear goes to an
 * admin; only a confident "accept" is published automatically.
 */
class GeminiSubmissionScreener implements SubmissionScreener
{
    /** Below this confidence an "accept" is treated as "review". */
    private const MIN_CONFIDENCE = 0.6;

    public function __construct(
        protected GeminiClient $gemini,
        protected GeminiDocumentIngestor $pdf,
    ) {}

    public function screen(Artifact $artifact): ScreeningResult
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->pdf->textOf($artifact)));
        $excerpt = mb_substr($text, 0, (int) config('idr.screening.excerpt_chars', 6000));

        if (mb_strlen($excerpt) < 200) {
            return new ScreeningResult(ScreeningResult::REVIEW, 'Too little readable text to judge the document.');
        }

        $reply = $this->gemini->generate($this->instructions(), [[
            'role' => 'user',
            'text' => implode("\n", [
                'Title entered by the student: '.$artifact->title,
                'Declared type: '.($artifact->document_type?->label() ?? 'not given'),
                'Course code: '.($artifact->course_code ?? 'not given'),
                'Academic year: '.($artifact->academic_year ?? 'not given'),
                '',
                'Opening text of the PDF:',
                '"""',
                $excerpt,
                '"""',
            ]),
        ]], 0.0);

        return $this->parse($reply);
    }

    protected function instructions(): string
    {
        return <<<'TXT'
You screen uploads to the American University of Nigeria Digital Repository, which only accepts
final-year capstone work: Senior Design Projects (building or designing a system, product or
prototype) and Senior Research Projects (a study with research questions, method and findings).

Decide whether the document is clearly such a project report and matches the declared type and title.
Answer "review" if it looks like anything else: lecture notes, slides, an exam or past paper, an
assignment, a CV, a form, a blank or mostly unreadable file, unrelated or inappropriate content, a
report that does not match the title, or the wrong project type. Also answer "review" when you are unsure.

Reply with JSON only, no other text:
{"verdict": "accept" | "review", "confidence": number between 0 and 1, "reason": "one short sentence"}
TXT;
    }

    protected function parse(string $reply): ScreeningResult
    {
        $data = preg_match('/\{.*\}/s', $reply, $m) ? json_decode($m[0], true) : null;

        if (! is_array($data) || ! in_array($data['verdict'] ?? null, [ScreeningResult::ACCEPT, ScreeningResult::REVIEW], true)) {
            return new ScreeningResult(ScreeningResult::REVIEW, 'The AI check gave an unclear answer.');
        }

        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 300) ?: 'No reason given.';
        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;

        if ($data['verdict'] === ScreeningResult::ACCEPT && $confidence < self::MIN_CONFIDENCE) {
            return new ScreeningResult(ScreeningResult::REVIEW, 'Low confidence: '.$reason);
        }

        return new ScreeningResult($data['verdict'], $reason);
    }
}
