<?php
declare(strict_types=1);

function normalize_mobile_10(?string $phone): string|false
{
    if ($phone === null) {
        return false;
    }

    // Remove all non-digit characters
    $clean = preg_replace('/\D+/', '', trim($phone));
    if ($clean === '') {
        return false;
    }

    // Remove leading 0 if entered as 0987654321
    if (strlen($clean) === 11 && $clean[0] === '0') {
        $clean = substr($clean, 1);
    }

    // Remove +91 if passed by mistake
    if (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
        $clean = substr($clean, 2);
    }

    // Must be exactly 10 digits and start with 6,7,8,9
    if (strlen($clean) === 10 && preg_match('/^[6-9]\d{9}$/', $clean)) {
        return $clean;
    }

    return false;
}

function is_valid_mobile_10(?string $phone): bool
{
    return normalize_mobile_10($phone) !== false;
}
