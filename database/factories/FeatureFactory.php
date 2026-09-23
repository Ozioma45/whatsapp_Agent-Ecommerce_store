<?php

namespace Database\Factories;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'key' => str($name)->slug('_'),
            'name' => $name,
            'description' => fake()->optional()->sentence(),
            'type' => Feature::TYPE_BOOLEAN,
        ];
    }
}
