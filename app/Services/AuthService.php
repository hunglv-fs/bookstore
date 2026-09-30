<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Register a customer account: the login user and its customer profile
     * are created atomically so a user can never exist without a profile.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     */
    public function registerCustomer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
            ]);
            $user->role_id = Role::idFor(RoleName::Customer);
            $user->is_active = true;
            $user->save();

            $user->customer()->create([
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);

            return $user;
        });
    }

    /**
     * Verify credentials and return the user.
     *
     * Wrong email, wrong password and deactivated account all return the same
     * message so the endpoint cannot be used to probe which emails exist.
     *
     * @throws ValidationException
     */
    public function attemptLogin(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $user;
    }

    public function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName)->plainTextToken;
    }
}
