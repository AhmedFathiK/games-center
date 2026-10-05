<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('authenticated language choice is saved to the account and used on later requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/profile')
        ->post(route('locale.update'), ['locale' => 'ar'])
        ->assertRedirect('/settings/profile');

    expect($user->refresh()->locale)->toBe('ar');

    $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
        ->component('settings/Profile')
        ->where('locale', 'ar'));
});

test('language settings page uses the saved account preference', function () {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->actingAs($user)
        ->get(route('language.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Language')
            ->where('locale', 'ar')
            ->where('auth.user.locale', 'ar'));
});

test('guests keep their language choice in the session', function () {
    $this->from('/')->post(route('locale.update'), ['locale' => 'ar'])
        ->assertRedirect('/');

    expect(session('locale'))->toBe('ar');
});
