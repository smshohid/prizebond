<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Bond number / series normalisation. Numbers are strings, never integers,
 * so leading zeros ("0046900") are always preserved.
 */
final class BondNumber
{
    private const BN_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
    private const AR_DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public static function asciiDigits(string $s): string
    {
        return strtr($s, self::BN_DIGITS + self::AR_DIGITS);
    }

    /** @return array{0:?string,1:?string} [normalizedNumber, errorKey] */
    public static function number(string $raw, int $length = 7): array
    {
        $s = self::asciiDigits(trim($raw));
        $s = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $s) ?? '';
        if ($s === '') {
            return [null, 'bond.err_number_required'];
        }
        if (!preg_match('/^\d+$/', $s)) {
            return [null, 'bond.err_number_digits'];
        }
        if (strlen($s) !== $length) {
            return [null, 'bond.err_number_length'];
        }
        return [$s, null];
    }

    /** @return array{0:?string,1:?string} */
    public static function series(string $raw, bool $required = false): array
    {
        $s = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}\-]+/u', '', trim($raw)) ?? '';
        if ($s === '') {
            return $required ? [null, 'bond.err_series_required'] : ['', null];
        }
        if (!preg_match('/^[\p{L}\p{M}]{1,8}$/u', $s)) {
            return [null, 'bond.err_series_invalid'];
        }
        return [mb_strtoupper($s, 'UTF-8'), null];
    }

    public static function date(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [null, null];
        }
        $raw = self::asciiDigits($raw);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            $err = \DateTimeImmutable::getLastErrors();
            if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                if ($d > new \DateTimeImmutable('today')) {
                    return [null, 'bond.err_date_future'];
                }
                if ((int) $d->format('Y') < 1990) {
                    return [null, 'bond.err_date_invalid'];
                }
                return [$d->format('Y-m-d'), null];
            }
        }
        return [null, 'bond.err_date_invalid'];
    }
}
