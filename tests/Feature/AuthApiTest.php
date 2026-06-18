<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_user_can_login_and_read_current_user(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com');

        $this->withHeader('Referer', 'http://localhost/settings')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com');
    }

    public function test_login_returns_validation_error_for_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
