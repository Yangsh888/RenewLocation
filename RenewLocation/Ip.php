<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Ip
{
    private static ?\Czdb\DbSearcher $v4Searcher = null;
    private static ?\Czdb\DbSearcher $v6Searcher = null;

    public static function location(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }

        Loader::registerCzdbAutoload();

        $settings = Settings::load();
        $accessKey = (string) ($settings['accessKey'] ?? '');
        if ($accessKey === '') {
            return '';
        }

        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if (!$isV6 && !$isV4) {
            return '';
        }

        try {
            if ($isV6) {
                if (self::$v6Searcher === null) {
                    $path = Settings::dataRoot() . DIRECTORY_SEPARATOR . 'cz88_public_v6.czdb';
                    if (!file_exists($path)) {
                        return '';
                    }
                    self::$v6Searcher = new \Czdb\DbSearcher($path, \Czdb\DbSearcher::QUERY_TYPE_BTREE, $accessKey);
                }
                $region = self::$v6Searcher->search($ip);
            } else {
                if (self::$v4Searcher === null) {
                    $path = Settings::dataRoot() . DIRECTORY_SEPARATOR . 'cz88_public_v4.czdb';
                    if (!file_exists($path)) {
                        return '';
                    }
                    self::$v4Searcher = new \Czdb\DbSearcher($path, \Czdb\DbSearcher::QUERY_TYPE_BTREE, $accessKey);
                }
                $region = self::$v4Searcher->search($ip);
            }

            return self::formatRegion($region);
        } catch (\Throwable $e) {
            Settings::report('Ip.location', $e);
            return '';
        }
    }

    private static function formatRegion(?string $region): string
    {
        if ($region === null || $region === '') {
            return '';
        }

        $parts = self::regionParts($region);
        $result = [];
        $last = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '0' || $part === '中国' || $part === '局域网IP') {
                continue;
            }
            if (strpos($part, '数据中心') !== false || strpos($part, '上网公共出口') !== false || strpos($part, '通用') !== false) {
                continue;
            }
            if ($part !== $last && !str_contains($last, $part)) {
                $result[] = $part;
                $last = $part;
            }
        }

        $formatted = implode(' ', $result);
        if ($formatted !== '') {
            return Text::plain($formatted, 48);
        }

        $fallback = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '0' || $part === '局域网IP') {
                continue;
            }
            $fallback[] = $part;
        }

        return Text::plain(implode(' ', array_values(array_unique($fallback))), 48);
    }

    private static function regionParts(string $region): array
    {
        $parts = explode("\t", str_replace(['|', ' '], "\t", $region));
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }
}
