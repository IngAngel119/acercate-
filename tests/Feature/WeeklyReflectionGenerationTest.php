<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyReflectionGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_generate_weekly_reflection_from_entries(): void
    {
        $user = User::factory()->create();

        config()->set('services.openai.api_key', 'test-openai-key');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "1) Resumen de la semana\nTuviste una semana con altibajos y buena capacidad de recuperación.\n\n2) Patrones observados\nAparecen temas de familia, trabajo y rutina.\n\n3) Fortalezas y aprendizajes\nMostraste calma y esperanza incluso en días retadores.\n\n4) Intención para la próxima semana\nSostener hábitos que te ayudaron a cerrar mejor los días.",
                        ],
                    ],
                ],
            ], 200),
        ]);

        JournalEntry::query()->create([
            'user_id' => $user->id,
            'content' => 'I felt grateful and calm after spending time with family and improving my routine.',
            'entry_date' => '2026-04-13',
        ]);

        JournalEntry::query()->create([
            'user_id' => $user->id,
            'content' => 'Work was stressful, but I still felt hopeful and proud at the end of the day.',
            'entry_date' => '2026-04-15',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reflections/weekly/generate', [
            'week_start_date' => '2026-04-13',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('is_generated', true)
            ->assertJsonPath('week_start_date', '2026-04-13')
            ->assertJsonPath('week_end_date', '2026-04-19')
            ->assertJsonPath('content', "1) Resumen de la semana\nTuviste una semana con altibajos y buena capacidad de recuperación.\n\n2) Patrones observados\nAparecen temas de familia, trabajo y rutina.\n\n3) Fortalezas y aprendizajes\nMostraste calma y esperanza incluso en días retadores.\n\n4) Intención para la próxima semana\nSostener hábitos que te ayudaron a cerrar mejor los días.");

        $this->assertDatabaseHas('reflections', [
            'user_id' => $user->id,
            'week_start_date' => '2026-04-13',
            'week_end_date' => '2026-04-19',
            'is_generated' => 1,
        ]);
    }

    public function test_current_week_endpoint_returns_404_when_no_reflection_exists(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/reflections/weekly/current')->assertNotFound();
    }

    public function test_current_week_endpoint_returns_generated_reflection(): void
    {
        $user = User::factory()->create();

        JournalEntry::query()->create([
            'user_id' => $user->id,
            'content' => 'Feeling energized and motivated this week.',
            'entry_date' => now()->startOfWeek()->toDateString(),
        ]);

        Sanctum::actingAs($user);

        // Generate the reflection first
        $this->postJson('/api/reflections/weekly/generate')->assertCreated();

        // Then fetch via the current endpoint
        $this->getJson('/api/reflections/weekly/current')
            ->assertOk()
            ->assertJsonPath('is_generated', true);
    }
}
