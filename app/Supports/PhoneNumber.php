<?php

namespace App\Supports;

class PhoneNumber
{
    public static function normalizeEthiopian(?string $phone): ?string
    {
        if (! $phone) {
            return $phone;
        }

        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);
        $digits = ltrim($phone, '+');

        if (str_starts_with($digits, '251')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+251' . $digits;
    }
}