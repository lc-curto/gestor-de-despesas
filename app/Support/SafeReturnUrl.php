<?php

namespace App\Support;

use Illuminate\Http\Request;

final class SafeReturnUrl
{
    /**
     * Resolve an internal relative path without allowing external redirects.
     */
    public static function resolve(Request $request, string $fallback): string
    {
        $candidate = $request->query('return_to');

        if (! is_string($candidate) || $candidate === '') {
            return $fallback;
        }

        if (
            ! str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\r\n\x00-\x1F]/', $candidate)
        ) {
            return $fallback;
        }

        return $candidate;
    }
}
