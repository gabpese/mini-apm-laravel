<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
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
            'type' => Event::TYPE_FEATURE_USED,
            'name' => fake()->randomElement(['exportar_pdf', 'importar_csv', 'compartilhar']),
            'app_version' => fake()->randomElement(['1.0.0', '1.1.0', '1.2.0']),
            'user_ref' => 'u_'.fake()->lexify('????'),
            'occurred_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }

    public function crash(): static
    {
        return $this->state(fn () => [
            'type' => Event::TYPE_CRASH,
            'name' => null,
            'payload' => ['message' => 'Undefined method for nil', 'stack' => 'app.rb:10:in `run`'],
        ]);
    }
}
