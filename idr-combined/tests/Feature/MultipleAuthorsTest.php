<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Group projects: a submission lists every author, with optional student IDs. */
class MultipleAuthorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    private function upload(array $overrides = [])
    {
        $user = auth()->user();

        return $this->postJson('/api/documents/upload', array_merge([
            'file' => UploadedFile::fake()->createWithContent('group.pdf', "%PDF-1.4\ngroup project ".uniqid()."\n%%EOF\n"),
            'title' => 'Smart Irrigation Controller',
            'courseCode' => 'CSC 499',
            'academicYear' => '2025/2026',
            'documentType' => 'project',
            'declarationAccepted' => '1',
            'declarationName' => $user->name,
        ], $overrides));
    }

    public function test_a_group_submission_stores_every_author(): void
    {
        $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));

        $id = $this->upload(['authors' => [
            ['name' => ' Ada  Obi ', 'studentId' => 'a00012345'],
            ['name' => 'Musa Bello', 'studentId' => 'A00023456'],
            ['name' => 'Grace Eze'],
        ]])->assertStatus(202)->json('data.artifactId');

        $artifact = Artifact::find($id);
        $this->assertSame([
            ['name' => 'Ada Obi', 'studentId' => 'A00012345'],
            ['name' => 'Musa Bello', 'studentId' => 'A00023456'],
            ['name' => 'Grace Eze', 'studentId' => null],
        ], $artifact->authors);
        $this->assertSame('Ada Obi, Musa Bello & Grace Eze', $artifact->author);  // what search, chat and Qdrant use
        $this->assertStringContainsString('on behalf of all the authors listed', $artifact->declaration_text);
    }

    public function test_the_certificate_lists_the_group_and_the_public_sees_names_only(): void
    {
        $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));
        $id = $this->upload(['authors' => [['name' => 'Ada Obi', 'studentId' => 'A00012345'], ['name' => 'Musa Bello']]])->json('data.artifactId');

        $this->getJson("/api/documents/{$id}/certificate")
            ->assertOk()
            ->assertJsonPath('data.authors', [['name' => 'Ada Obi', 'studentId' => 'A00012345'], ['name' => 'Musa Bello', 'studentId' => null]]);

        Artifact::find($id)->forceFill(['status' => 'approved'])->save();
        $this->actingAsStudent(); // someone else
        $this->getJson("/api/documents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.authors', [['name' => 'Ada Obi'], ['name' => 'Musa Bello']]);
    }

    public function test_a_single_author_keeps_the_usual_declaration(): void
    {
        $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));
        $id = $this->upload(['authors' => [['name' => 'Ada Obi']]])->json('data.artifactId');

        $this->assertStringContainsString('my own original senior project', Artifact::find($id)->declaration_text);
    }

    public function test_the_old_single_author_field_is_split_into_names(): void
    {
        $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));
        $id = $this->upload(['author' => 'Ada Obi, Musa Bello and Grace Eze'])->json('data.artifactId');

        $this->assertSame(['Ada Obi', 'Musa Bello', 'Grace Eze'], array_column(Artifact::find($id)->authors, 'name'));
    }

    public function test_author_rows_are_validated(): void
    {
        $this->actingAsStudent(User::factory()->create(['name' => 'Ada Obi']));

        $this->upload(['authors' => [['name' => 'Ada Obi'], ['name' => '']]])->assertJsonValidationErrors('authors.1.name');
        $this->upload(['authors' => [['name' => 'Ada Obi', 'studentId' => 'not-an-id']]])->assertJsonValidationErrors('authors.0.studentId');
        $this->upload(['authors' => [['name' => 'Ada Obi'], ['name' => 'ada obi']]])->assertJsonValidationErrors('authors.1.name');
        $this->upload(['authors' => array_map(fn ($i) => ['name' => "Student Number {$i}"], range(1, 11))])->assertJsonValidationErrors('authors');
    }

    public function test_admins_can_correct_the_author_list(): void
    {
        $this->actingAsAdmin();
        $artifact = Artifact::factory()->create(['author' => 'Ada Obi']);

        $this->patchJson("/api/admin/documents/{$artifact->id}", ['author' => 'Ada Obi & Musa Bello'])->assertOk();
        $this->assertSame(['Ada Obi', 'Musa Bello'], array_column($artifact->fresh()->authors, 'name'));

        $this->patchJson("/api/admin/documents/{$artifact->id}", ['authors' => [['name' => 'Musa Bello', 'studentId' => 'A00023456']]])->assertOk();
        $this->assertSame('Musa Bello', $artifact->fresh()->author);
        $this->assertSame('A00023456', $artifact->fresh()->authors[0]['studentId']);
    }

    public function test_documents_saved_before_author_lists_still_show_their_authors(): void
    {
        $artifact = Artifact::factory()->create();
        Artifact::raw(fn ($c) => $c->updateOne(['_id' => new \MongoDB\BSON\ObjectId((string) $artifact->id)], ['$set' => ['author' => 'Ada Obi and Musa Bello'], '$unset' => ['authors' => '']]));

        $this->assertSame(['Ada Obi', 'Musa Bello'], array_column(Artifact::find($artifact->id)->authors, 'name'));
    }
}
