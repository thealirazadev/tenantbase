<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a user and logs them in', function (): void {
    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'Ada@Example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = User::firstWhere('email', 'ada@example.com');
    expect($user)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('rejects a duplicate email at registration', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs in with valid credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => Hash::make('correct-horse-battery'),
    ]);

    $this->post('/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function (): void {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => Hash::make('correct-horse-battery'),
    ]);

    $this->post('/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('throttles repeated login attempts from one address', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
    }

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertStatus(429);
});

it('logs the user out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

it('keeps the account screens off tenant subdomains', function (): void {
    $this->get('http://acme.tenantbase.test/login')->assertNotFound();
});
