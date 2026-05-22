<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Text
{
    public static function plain($value, int $max = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        
        if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        }

        return $value;
    }

    public static function ascii($value, int $max = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = trim((string) $value);
        $value = preg_replace('/[^a-zA-Z0-9\-_=]/', '', $value) ?? '';

        if ($max > 0 && strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    }

    public static function base64($value, int $max = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = trim((string) $value);
        $value = preg_replace('/[^a-zA-Z0-9+\/=_-]/', '', $value) ?? '';

        if ($max > 0 && strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    }

    public static function maskIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.*.*';
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 3) {
                return $parts[0] . ':' . $parts[1] . ':*:*';
            }
        }

        return '';
    }
}
