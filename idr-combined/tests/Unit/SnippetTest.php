<?php

namespace Tests\Unit;

use App\Support\Snippet;
use PHPUnit\Framework\TestCase;

class SnippetTest extends TestCase
{
    public function test_short_text_is_returned_unchanged(): void
    {
        $this->assertSame('Neural networks operate...', Snippet::make('Neural networks operate...', 'neural networks'));
        $this->assertSame('approved text', Snippet::make('approved text', 'anything'));
    }

    public function test_title_page_and_keywords_are_skipped(): void
    {
        $chunk = 'AMERICAN UNIVERSITY OF NIGERIA, YOLA School of Information Technology and Computing Department of '
            .'Information Systems Phishing Susceptibility Among University Staff SENIOR RESEARCH PROJECT Submitted in partial '
            .'fulfilment of the requirements for the award of the degree By Yusuf Aliyu Student ID: A00021933 Supervisor: Dr. X '
            .'Abstract Phishing emails are the most common way attackers gain access to university systems. This study sent '
            .'simulated emails to 420 staff. Keywords: phishing, awareness, social engineering 3 1. Introduction Universities '
            .'hold large amounts of personal data.';

        $snippet = Snippet::make($chunk, 'phishing');

        $this->assertStringStartsWith('Phishing emails are the most common way', $snippet);
        $this->assertStringNotContainsString('AMERICAN UNIVERSITY', $snippet);
        $this->assertStringNotContainsString('Keywords', $snippet);
    }

    public function test_it_starts_at_the_sentence_that_mentions_the_query(): void
    {
        $chunk = 'or after a 30-minute safety limit. Thresholds for tomato were set at 32% and 45% after calibrating the sensors '
            .'against oven-dried soil samples from the trial site. Requirements were gathered through interviews with six '
            .'farmers from the cooperative. Their main requests were a display in Hausa as well as English. The controlled plot '
            .'used 41% less water than the plot on a fixed schedule while producing a comparable yield. Each node costs about '
            .'NGN 68,000 to build, which makes the system realistic for cooperatives.';

        // "farmers" (farm) and "water" are both query words; it starts at the first such sentence.
        $snippet = Snippet::make($chunk, 'saving water on farms');

        $this->assertStringStartsWith('Requirements were gathered through interviews with six farmers', $snippet);
        $this->assertStringContainsString('41% less water', $snippet);
        $this->assertStringNotContainsString('safety limit', $snippet);
        $this->assertLessThanOrEqual(320, mb_strlen($snippet));
    }

    public function test_a_leading_half_sentence_is_dropped_when_nothing_matches_by_word(): void
    {
        $chunk = 'ending of an earlier sentence that was cut by the chunker. '.str_repeat('A complete sentence about sensors follows here. ', 10);

        $this->assertStringStartsWith('A complete sentence about sensors', Snippet::make($chunk, 'zzz'));
    }

    public function test_reference_lists_are_left_out(): void
    {
        $chunk = str_repeat('Future work should add more crops and seasons to the dataset. ', 5)
            .'References Breiman, L. (2001). Random forests. Machine Learning, 45(1), 5-32. Tan, M., & Le, Q. (2019). EfficientNet.';

        $this->assertStringNotContainsString('Breiman', Snippet::make($chunk, 'random forests'));
    }

    public function test_section_headings_stuck_to_a_sentence_are_removed(): void
    {
        $chunk = str_repeat('Earlier text about the pilot and its results in two hostels. ', 4)
            .'Conclusion and Recommendations The system makes hostel allocation faster and more transparent. '
            .'Results Table 1 summarises the trial. Results show that waiting fell.';

        $snippet = Snippet::make($chunk, 'hostel allocation');

        $this->assertStringStartsWith('Earlier text', Snippet::make($chunk, 'zzz'));
        $this->assertStringContainsString('The system makes hostel allocation faster', $snippet);
        $this->assertStringNotContainsString('Recommendations', $snippet);
        $this->assertStringContainsString('Table 1 summarises the trial.', $snippet);
        $this->assertStringContainsString('Results show that waiting fell.', $snippet); // a real sentence keeps its first word
    }

    public function test_a_numbered_heading_is_removed(): void
    {
        $chunk = 'Training used the Adam optimiser in TensorFlow. '.str_repeat('Filler sentence for length here. ', 8)
            .'3. Methodology Dataset: 3,560 maize images were combined with 1,260 field photos.';

        $this->assertStringStartsWith('Dataset: 3,560 maize images', Snippet::make($chunk, 'maize images dataset'));
    }
}
