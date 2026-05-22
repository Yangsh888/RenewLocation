<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

use Typecho\Common;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;
use Utils\Pref;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Settings
{
    private const NAME = 'RenewLocation';
    private const CACHE_KEY = 'renewlocation:settings:v1';
    private static ?array $runtime = null;

    public static function load(): array
    {
        return Pref::load(
            self::$runtime,
            self::CACHE_KEY,
            self::defaults(),
            static fn(): array => self::readStored('load.read'),
            [self::class, 'normalize'],
            [self::class, 'ensureStored'],
            [self::class, 'report']
        );
    }

    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            'showSystem' => '1',
            'showBrowser' => '1',
            'showLocation' => '1',
            'showIpText' => '0',
            'autoUpdate' => '1',
            'updateDays' => 7,
            'timeout' => 8,
            'updateUrl' => '',
            'accessKey' => '',
            'updateSecret' => '',
            'lastUpdateAt' => 0,
            'lastDispatchAt' => 0,
        ];
    }

    public static function boolKeys(): array
    {
        return [
            'enabled',
            'showSystem',
            'showBrowser',
            'showLocation',
            'showIpText',
            'autoUpdate',
        ];
    }

    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $settings = array_intersect_key(array_merge($defaults, $settings), $defaults);

        foreach (self::boolKeys() as $key) {
            $settings[$key] = self::bool($settings[$key] ?? ($defaults[$key] ?? '0'));
        }

        $settings['updateDays'] = self::int($settings['updateDays'] ?? 7, 1, 90, 7);
        $settings['timeout'] = self::int($settings['timeout'] ?? 8, 2, 20, 8);
        $settings['updateUrl'] = self::url($settings['updateUrl'] ?? '');
        $settings['accessKey'] = Text::base64($settings['accessKey'] ?? '', 255);
        $settings['updateSecret'] = Text::ascii($settings['updateSecret'] ?? '', 64);
        if ($settings['updateSecret'] === '') {
            $settings['updateSecret'] = bin2hex(Common::secureRandomBytes(16));
        }
        $settings['lastUpdateAt'] = max(0, self::int($settings['lastUpdateAt'] ?? 0, 0, PHP_INT_MAX, 0));
        $settings['lastDispatchAt'] = max(0, self::int($settings['lastDispatchAt'] ?? 0, 0, PHP_INT_MAX, 0));

        return $settings;
    }

    public static function store(array $settings): void
    {
        $settings = self::normalize($settings);
        \Widget\Plugins\Edit::configPlugin(self::NAME, $settings);
        self::clear();
    }

    public static function ensureStored(): void
    {
        Pref::sync(
            self::NAME,
            self::defaults(),
            [self::class, 'normalize'],
            [self::class, 'report'],
            null,
            static fn(): array => self::readStored('ensure.read')
        );
        self::clear();
    }

    public static function clear(): void
    {
        Pref::forget(self::$runtime, self::CACHE_KEY, [self::class, 'report']);
    }

    public static function actionUrl(string $do = ''): string
    {
        $path = '/action/renew-location';
        if ($do !== '') {
            $query = ['do' => $do];
            if ($do === 'update') {
                $settings = self::load();
                $query['token'] = Common::timeToken((string) ($settings['updateSecret'] ?? ''));
            }
            $path .= '?' . http_build_query($query);
        }

        return Common::url($path, (string) Helper::options()->index);
    }

    public static function dataRoot(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'data';
    }

    public static function report(string $scope, \Throwable $e): void
    {
        error_log('RenewLocation.' . $scope . ': ' . $e->getMessage());
    }

    private static function readStored(string $scope): array
    {
        try {
            return (array) Helper::options()->plugin(self::NAME)->toArray();
        } catch (PluginException) {
            return [];
        } catch (\Throwable $e) {
            self::report($scope, $e);
            return [];
        }
    }

    private static function bool($value): string
    {
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
    }

    private static function int($value, int $min, int $max, int $default): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private static function url($value): string
    {
        $value = Text::plain($value, 2048);
        if ($value === '') {
            return '';
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return $scheme === 'https' ? $value : '';
    }
}
