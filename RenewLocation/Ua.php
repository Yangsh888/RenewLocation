<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Ua
{
    private static array $cache = [];

    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['system' => '', 'browser' => ''];
        }

        if (isset(self::$cache[$ua])) {
            return self::$cache[$ua];
        }

        $result = [
            'system' => self::system($ua),
            'browser' => self::browser($ua),
        ];

        self::$cache[$ua] = $result;
        return $result;
    }

    private static function browser(string $ua): string
    {
        if (preg_match('/MicroMessenger/i', $ua)) {
            return '微信';
        }
        if (preg_match('/QQBrowser/i', $ua)) {
            return 'QQ浏览器';
        }
        if (preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
            return 'Edge';
        }
        if (preg_match('/(?:OPR|Opera)\//i', $ua)) {
            return 'Opera';
        }
        if (preg_match('/(?:Firefox|FxiOS)\//i', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/SamsungBrowser\//i', $ua)) {
            return '三星浏览器';
        }
        if (preg_match('/UCBrowser\//i', $ua)) {
            return 'UCBrowser';
        }
        if (preg_match('/(?:Chrome|CriOS)\//i', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/Safari\//i', $ua)) {
            return 'Safari';
        }
        if (preg_match('/(?:MSIE|Trident)/i', $ua)) {
            return 'IE';
        }

        if (self::isBot($ua)) {
            return '爬虫';
        }

        if (self::isScript($ua)) {
            return '终端/脚本';
        }

        return '';
    }

    private static function system(string $ua): string
    {
        if (preg_match('/HarmonyOS/i', $ua)) {
            return 'HarmonyOS';
        }
        if (preg_match('/Windows NT 10\.0/i', $ua)) {
            return 'Win10/11';
        }
        if (preg_match('/Windows NT 6\.[23]/i', $ua)) {
            return 'Win8';
        }
        if (preg_match('/Windows NT 6\.1/i', $ua)) {
            return 'Win7';
        }
        if (preg_match('/Windows NT/i', $ua)) {
            return 'Win';
        }
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/(?:iPhone|iPad|iPod)/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/Mac OS X/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/Ubuntu/i', $ua)) {
            return 'Ubuntu';
        }
        if (preg_match('/Debian/i', $ua)) {
            return 'Debian';
        }
        if (preg_match('/CentOS/i', $ua)) {
            return 'CentOS';
        }
        if (preg_match('/Fedora/i', $ua)) {
            return 'Fedora';
        }
        if (preg_match('/Linux|X11/i', $ua)) {
            return 'Linux';
        }

        if (self::isBot($ua)) {
            return '机器人环境';
        }

        if (self::isScript($ua)) {
            return '脚本环境';
        }

        return '';
    }

    private static function isBot(string $ua): bool
    {
        return preg_match('/bot|spider|crawler|slurp|bytespider|claudebot|gptbot|chatgpt-user|perplexitybot|bingpreview/i', $ua) === 1;
    }

    private static function isScript(string $ua): bool
    {
        return preg_match('/curl|wget|python-requests|python-urllib|aiohttp|go-http-client|okhttp|apache-httpclient|postmanruntime|insomnia|powershell|httpclient|guzzlehttp|symfony-http-client/i', $ua) === 1;
    }
}
