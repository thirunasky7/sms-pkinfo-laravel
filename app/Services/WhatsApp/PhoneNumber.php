<?php

namespace App\Services\WhatsApp;

class PhoneNumber
{
    /**
     * Normalise to E.164 digits without the leading "+" (the format WhatsApp uses).
     * Returns null when the input cannot be a valid international number.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $trimmed = trim($input);
        if ($trimmed === '' || preg_match('/[^\d\s\-\+\(\)\.]/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);
        if (str_starts_with($trimmed, '00')) {
            $digits = substr($digits, 2);
        }

        // E.164: max 15 digits, country codes never start with 0.
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }

        return $digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }
}
