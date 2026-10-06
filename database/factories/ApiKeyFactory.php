<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = 'apm_'.Str::random(40);

        return [
            'project_id' => Project::factory(),
            'name' => fake()->word(),
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => ApiKey::hash($plain),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
