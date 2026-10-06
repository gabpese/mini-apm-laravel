<?php

namespace Database\Factories;

use App\Models\ErrorGroup;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErrorGroup>
 */
class ErrorGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $message = fake()->sentence();
        $firstSeen = fake()->dateTimeBetween('-30 days', '-1 day');

        return [
            'project_id' => Project::factory(),
            'fingerprint' => ErrorGroup::fingerprintFor($message),
            'message' => $message,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $firstSeen,
            'occurrences' => 1,
        ];
    }
}
