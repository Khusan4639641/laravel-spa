<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_user_logs_in_reads_profile_and_logs_out_using_session(): void
    {
        $this->seed();
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', [
            'email' => 'admin@example.com', 'password' => 'password',
        ])->assertOk()->assertJsonPath('data.email', 'admin@example.com')->assertDontSee('password');
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_bad_credentials_and_missing_fields_are_distinct(): void
    {
        $this->seed();
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited_and_registration_is_absent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', ['email' => 'x@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'x@example.com', 'password' => 'wrong'])->assertStatus(429);
        $this->postJson('/api/register', [])->assertNotFound();
    }

    public function test_csrf_is_required_for_stateful_mutating_requests(): void
    {
        // Laravel normally skips CSRF in tests; change only the environment check for this request.
        $this->app->instance('env', 'local');
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', ['email' => 'x@example.com', 'password' => 'wrong'])->assertStatus(419);
    }

    public function test_seeding_does_not_reset_an_existing_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'custom-password']);
        $hash = $user->password;
        $this->seed();
        $this->assertSame($hash, $user->refresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_bearer_tokens_are_rejected_without_requiring_a_token_table(): void
    {
        $this->withToken('1|untrusted-token')->getJson('/api/me')->assertUnauthorized();
    }
}
