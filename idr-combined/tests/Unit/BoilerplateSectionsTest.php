<?php

namespace Tests\Unit;

use App\Support\BoilerplateSections;
use PHPUnit\Framework\TestCase;

class BoilerplateSectionsTest extends TestCase
{
    private function prose(int $sentences = 40): string
    {
        return str_repeat('The controller opened the valve when the soil was dry and the farmer could see each reading on a phone. ', $sentences);
    }

    public function test_front_matter_before_the_abstract_is_skipped(): void
    {
        $sections = [
            0 => 'AMERICAN UNIVERSITY OF NIGERIA '.str_repeat('CERTIFICATION This is to certify that this project was carried out by the student. ', 30),
            1 => str_repeat('DEDICATION This work is dedicated to my family. ', 30).'Abstract Smallholder farms lose water.',
            2 => $this->prose(),
            3 => $this->prose(),
            4 => $this->prose(),
        ];

        $this->assertSame([0 => 'front matter', 1 => 'front matter'], BoilerplateSections::find($sections));
    }

    public function test_a_section_that_is_mostly_abstract_is_kept(): void
    {
        $sections = [0 => 'AMERICAN UNIVERSITY OF NIGERIA, YOLA Senior Design Project By A. Student Abstract '.$this->prose(), 1 => $this->prose()];

        $this->assertSame([], BoilerplateSections::find($sections));
    }

    public function test_the_reference_list_is_skipped_but_appendices_are_not(): void
    {
        $refs = str_repeat('Okafor, A. B. (2019). Irrigation in the North East. Journal of Agriculture, 12(3), 45-67. ', 30);
        $sections = [
            0 => 'Abstract '.$this->prose(),
            1 => $this->prose(),
            2 => $this->prose(),
            3 => $this->prose(10).' References '.$refs,
            4 => $refs,
            5 => 'Appendix A '.$this->prose(),
        ];

        $this->assertSame([3 => 'references', 4 => 'references'], BoilerplateSections::find($sections));
    }

    public function test_a_literature_review_full_of_citations_is_kept(): void
    {
        $review = str_repeat('Okafor (2019) found that drip irrigation saves water, while Bello et al. (2021) reported similar gains (Adamu, 2018; Garba and Eze, 2022). ', 20);

        $this->assertSame([], BoilerplateSections::find([0 => 'Abstract '.$this->prose(), 1 => $review, 2 => $this->prose()]));
    }

    public function test_a_table_of_contents_is_skipped(): void
    {
        $toc = 'TABLE OF CONTENTS '.str_repeat('Chapter One Introduction ........ 1 ', 20);

        $this->assertSame([1 => 'contents'], BoilerplateSections::find([0 => 'Abstract '.$this->prose(), 1 => $toc, 2 => $this->prose(), 3 => $this->prose()]));
    }
}
