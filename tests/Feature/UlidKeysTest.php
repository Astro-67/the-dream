<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

test('a newly registered user is identified by a ULID', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(Str::isUlid(Auth::id()))->toBeTrue();
    expect(User::find(Auth::id())?->email)->toBe('test@example.com');
});
