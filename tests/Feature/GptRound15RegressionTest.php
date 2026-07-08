<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the GPT round-15 findings:
 * 1. Docker build context could bake local secrets/internals into the image
 *    (no .dockerignore next to a COPY . . Dockerfile).
 * 2. /api/note/{id} misses bypassed the API's JSON 404 contract via
 *    firstOrFail().
 * 3. The password-reset form leaked account existence by answering unknown
 *    emails differently from known ones.
 */
class GptRound15RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dockerignore_excludes_secrets_and_repo_internals()
    {
        // COPY . . without these exclusions bakes .env / .git / local vendor
        // into every locally built image.
        $this->assertFileExists(base_path('.dockerignore'));
        $rules = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach (['.env', '.env.*', '.git', 'vendor', 'node_modules', 'tests'] as $required) {
            $this->assertContains($required, $rules, ".dockerignore lost the '$required' exclusion");
        }
    }

    public function test_api_note_miss_returns_the_api_json_404_shape()
    {
        $plain = Str::random(40);
        User::factory()->create(['api_token' => User::hashApiToken($plain)]);

        $this->getJson('/api/note/does-not-exist', ['Authorization' => 'Bearer ' . $plain])
            ->assertStatus(404)
            ->assertExactJson(['error' => 'Not found']);
    }

    public function test_api_note_hit_still_returns_plain_text()
    {
        $plain = Str::random(40);
        User::factory()->create(['api_token' => User::hashApiToken($plain)]);
        Note::create(['id' => 'gpt15not', 'service_id' => 'gpt15svc', 'note' => 'remember the thing']);

        $this->getJson('/api/note/gpt15not', ['Authorization' => 'Bearer ' . $plain])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('remember the thing');
    }

    public function test_password_reset_response_is_identical_for_unknown_and_known_emails()
    {
        $user = User::factory()->create();

        // Same generic outcome either way: a status flash, never a field
        // error that would confirm which accounts exist.
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();
        $knownStatus = session('status');

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->assertSame($knownStatus, session('status'));
    }
}
