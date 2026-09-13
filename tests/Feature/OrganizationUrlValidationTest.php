<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyncTestCase;

class OrganizationUrlValidationTest extends SyncTestCase
{
    public static function invalidUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'https://example.com/maps/org/demo/1/', 'https://yandex.ru.evil.com/maps/org/a/1/',
            'https://evil.yandex.ru/maps/org/a/1/', 'https://yandex.ru:8080/maps/org/a/1/',
            'https://root:password@yandex.ru/maps/org/a/1/', 'https://yandex.ru/foo/maps/org/a/1/',
            'https://yandex.ru/maps/org/', 'https://yandex.ru/maps/213/moscow/',
            'https://127.0.0.1/maps/org/a/1/', 'file:///etc/passwd', 'https://yandex.ru/maps/org/a/1/../../',
            'https://yandex.ru/maps/org/a/1/;echo-test', 'javascript:alert(1)',
        ]);
    }

    #[DataProvider('invalidUrls')]
    public function test_backend_rejects_invalid_or_unsafe_urls(string $url): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->postJson('/api/organization', ['source_url' => $url])
            ->assertUnprocessable()->assertJsonValidationErrors('source_url');
        $this->assertDatabaseCount('organizations', 0);
        Queue::assertNothingPushed();
    }

    public function test_supported_domains_and_queries_are_normalized(): void
    {
        Queue::fake();
        foreach (['yandex.ru', 'yandex.com', 'yandex.uz', 'yandex.kz', 'www.yandex.by', 'yandex.com.tr'] as $host) {
            $this->actingAs(User::factory()->create())->postJson('/api/organization', ['source_url' => 'http://'.$host.'/maps/org/demo/123456789/reviews/?z=15&tab=reviews'])
                ->assertAccepted();
        }
        $this->assertDatabaseHas('organizations', ['normalized_url' => 'https://yandex.uz/maps/org/123456789/']);
    }
}
