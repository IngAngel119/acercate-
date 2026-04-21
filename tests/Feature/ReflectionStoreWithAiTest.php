<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReflectionStoreWithAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_reflection_uses_ai_when_openai_is_configured(): void
    {
        $user = User::factory()->create();

        JournalEntry::query()->create([
            'user_id' => $user->id,
            'content' => 'Tuve una semana intensa en el trabajo, pero con momentos de gratitud.',
            'entry_date' => '2026-04-14',
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');
        config()->set('services.openai.model', 'gpt-4.1-mini');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Esta semana mostró retos laborales, pero también resiliencia y enfoque en lo importante.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reflections', [
            'content' => 'Me sentí con altibajos esta semana.',
            'reflection_date' => '2026-04-16',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('is_generated', true)
            ->assertJsonPath('content', 'Esta semana mostró retos laborales, pero también resiliencia y enfoque en lo importante.');
    }

    public function test_store_reflection_falls_back_to_user_content_without_openai_key(): void
    {
        $user = User::factory()->create();

        config()->set('services.openai.api_key', null);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reflections', [
            'content' => 'Este texto debe quedarse igual si no hay clave.',
            'reflection_date' => '2026-04-16',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('is_generated', false)
            ->assertJsonPath('content', 'Este texto debe quedarse igual si no hay clave.');
    }
}
