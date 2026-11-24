<?php

namespace Tests\Unit;

use App\Models\Artifact;
use App\Rules\AcademicYear;
use App\Rules\AllowedEmailDomain;
use App\Rules\CourseCode;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationRulesTest extends TestCase
{
    private function passes(object $rule, mixed $value): bool
    {
        return Validator::make(['v' => $value], ['v' => [$rule]])->passes();
    }

    public function test_email_domain_rule(): void
    {
        foreach (['a@aun.edu.ng', 'First.Last@AUN.EDU.NG', '  a.b@aun.edu.ng '] as $ok) {
            $this->assertTrue(AllowedEmailDomain::isAllowed($ok), $ok);
        }

        foreach (['a@gmail.com', 'a@aun.edu.ng.evil.com', 'a@x.aun.edu.ng', 'a@evil.com@aun.edu.ng', '@aun.edu.ng', 'aun.edu.ng', null, 42] as $bad) {
            $this->assertFalse(AllowedEmailDomain::isAllowed($bad), var_export($bad, true));
        }
    }

    public function test_email_domain_is_configurable(): void
    {
        config(['idr.auth.allowed_email_domain' => 'example.edu']);

        $this->assertTrue(AllowedEmailDomain::isAllowed('a@example.edu'));
        $this->assertFalse(AllowedEmailDomain::isAllowed('a@aun.edu.ng'));
    }

    public function test_course_code_rule_and_normalisation(): void
    {
        foreach (['CSC 401', 'csc401', 'SEN 499A', 'MTH  101'] as $ok) {
            $this->assertTrue($this->passes(new CourseCode, $ok), $ok);
        }
        foreach (['Computer Science', 'C 401', 'CSC 41', 'CSC-401', 'CSC 4011'] as $bad) {
            $this->assertFalse($this->passes(new CourseCode, $bad), $bad);
        }

        $this->assertSame('CSC 401', Artifact::normalizeCourseCode('csc401'));
        $this->assertSame('MTH 101', Artifact::normalizeCourseCode('MTH  101'));
        $this->assertSame('SEN 499A', Artifact::normalizeCourseCode('sen 499a'));
    }

    public function test_academic_year_rule_and_normalisation(): void
    {
        $this->assertTrue($this->passes(new AcademicYear, '2024/2025'));
        $this->assertTrue($this->passes(new AcademicYear, '2024 / 2025'));

        foreach (['2024/2026', '2024-2025', '2024', '1999/2000', '3000/3001'] as $bad) {
            $this->assertFalse($this->passes(new AcademicYear, $bad), $bad);
        }

        $this->assertSame('2024/2025', Artifact::normalizeAcademicYear('2024 / 2025'));
    }

    public function test_download_filename_is_safe(): void
    {
        $artifact = new Artifact(['title' => 'Exam: "Final" <script>/../../etc', 'course_code' => 'CSC 401']);

        $this->assertSame('CSC 401 Exam Final script....etc.pdf', $artifact->downloadFilename());
    }
}
