<?php

namespace App\Utils;

final class EmailIdentity
{
    /**
     * Use the same normalization for account lookups and per-mailbox rate limits.
     * Keep the existing Xboard lowercasing convention for compatibility.
     */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
