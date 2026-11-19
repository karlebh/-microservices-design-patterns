<?php

namespace Patterns\DataArchitecture\ReadReplicas\Models;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'completed' => fake()->boolean(),
        ];
    }
}
