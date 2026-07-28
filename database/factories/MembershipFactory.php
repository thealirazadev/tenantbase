<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => 'member',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Membership $membership): void {
            if ($membership->tenant_id === null) {
                throw new InvalidArgumentException('MembershipFactory requires an explicit tenant_id.');
            }
        });
    }

    /**
     * tenant_id is not fillable on the model, so the factory force-fills it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Membership
    {
        return (new Membership)->forceFill($attributes);
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'owner']);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'admin']);
    }
}
