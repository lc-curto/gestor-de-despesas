<?php

namespace App\Providers;

use Illuminate\Hashing\BcryptHasher;

/**
 * Supports legacy bcrypt hashes using the $2a$ prefix.
 * The stored hash is normalized only in memory during verification.
 */
class LegacyBcryptHasher extends BcryptHasher
{
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (is_string($hashedValue) && str_starts_with($hashedValue, '$2a$')) {
            $hashedValue = '$2y$'.substr($hashedValue, 4);
        }

        return parent::check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        // Do not replace legacy hashes after a successful login.
        if (is_string($hashedValue) && str_starts_with($hashedValue, '$2a$')) {
            return false;
        }

        return parent::needsRehash($hashedValue, $options);
    }
}
