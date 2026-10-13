<?php

namespace App\Support;

class DemoToken
{
    private const SESSION_KEY = 'demo_token_ok';

    public static function unlocked(): bool
    {
        return (bool) session(self::SESSION_KEY, false);
    }

    /** Fails closed: with no DEMO_TOKEN configured nobody can unlock. */
    public static function attempt(string $given): bool
    {
        $expected = (string) config('chirak.demo_token');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return false;
        }

        session([self::SESSION_KEY => true]);

        return true;
    }
}
