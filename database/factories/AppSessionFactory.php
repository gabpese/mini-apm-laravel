<?php

namespace Database\Factories;

use App\Models\AppSession;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppSession>
 */
class AppSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_ref' => 'u_'.fake()->lexify('????'),
            'app_version' => fake()->randomElement(['1.0.0', '1.1.0', '1.2.0']),
            'os' => fake()->randomElement(['Windows 11', 'Windows 10', 'macOS 14', 'Ubuntu 24.04']),
            'ram_mb' => fake()->randomElement([4096, 8192, 16384, 32768]),
            'gpu' => fake()->randomElement(['GTX 1660', 'RTX 3060', 'Intel UHD', 'Apple M2']),
            'started_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
