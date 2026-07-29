<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Project $project): void {
            if ($project->tenant_id === null) {
                throw new InvalidArgumentException('ProjectFactory requires an explicit tenant_id.');
            }
        });
    }

    /**
     * tenant_id is not fillable on the model, so the factory force-fills it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Project
    {
        return (new Project)->forceFill($attributes);
    }
}
