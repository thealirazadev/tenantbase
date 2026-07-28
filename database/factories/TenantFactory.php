<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 24, '').'-'.Str::lower(Str::random(6)),
            'plan' => config('plans.default'),
            'suspended_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => ['suspended_at' => now()]);
    }
}
