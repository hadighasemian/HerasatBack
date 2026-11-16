<?php

namespace Database\Factories;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Todo> */
class TodoFactory extends Factory
{
    protected $model = Todo::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'کار آزمایشی',
            'notes' => null,
            'due_date' => null,
            'completed_at' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['completed_at' => now()]);
    }
}
