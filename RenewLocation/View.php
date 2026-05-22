<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

use Typecho\Common;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class View
{
    private static bool $stylePrinted = false;

    public static function renderComment($comment): void
    {
        $agent = '';
        $ip = '';

        if (is_object($comment)) {
            $agent = (string) ($comment->agent ?? '');
            $ip = (string) ($comment->ip ?? '');
        } elseif (is_array($comment)) {
            $agent = (string) ($comment['agent'] ?? '');
            $ip = (string) ($comment['ip'] ?? '');
        }

        echo self::html($agent, $ip);
    }

    public static function html(?string $agent, ?string $ip): string
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1' || !self::hasDisplay($settings)) {
            return '';
        }

        $items = self::items([
            'agent' => (string) $agent,
            'ip' => (string) $ip,
        ], $settings);

        if ($items === []) {
            return '';
        }

        $html = '<span class="renew-location-tags">';
        foreach ($items as $item) {
            $html .= self::renderTag($item);
        }
        $html .= '</span>';

        return $html;
    }

    public static function header(string $_header, object $archive): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1' || !self::hasDisplay($settings)) {
            return;
        }

        if (!method_exists($archive, 'is') || self::$stylePrinted) {
            return;
        }

        if (!$archive->is('single') && !$archive->is('page')) {
            return;
        }

        self::$stylePrinted = true;
        echo '<style>'
            . '.renew-location-tags{display:inline-flex;align-items:center;gap:6px;min-width:0;max-width:calc(100% - 5.5em);vertical-align:middle;overflow:visible;flex:0 1 auto;}'
            . '.renew-location-tag{position:relative;display:inline-flex;align-items:center;gap:5px;min-width:0;max-width:9.4em;padding:4px 7px;border-radius:4px;font-size:12px;line-height:1.2;font-weight:500;white-space:nowrap;vertical-align:middle;flex:0 1 auto;}'
            . '.renew-location-tag.is-browser{background-color:rgba(59,130,246,0.1);color:#3b82f6;}'
            . '.renew-location-tag.is-system{background-color:rgba(16,185,129,0.1);color:#10b981;}'
            . '.renew-location-tag.is-geo{background-color:rgba(245,158,11,0.1);color:#f59e0b;}'
            . '.renew-location-tag.has-tip{cursor:help;}'
            . '.renew-location-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;flex:0 0 14px;}'
            . '.renew-location-icon svg{display:block;width:14px;height:14px;fill:currentColor}'
            . '.renew-location-label{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis}'
            . '.renew-location-tag.is-system{max-width:8.2em;}'
            . '.renew-location-tag.is-geo{max-width:11.2em;}'
            . '.renew-location-tag.is-geo .renew-location-label{max-width:7.8em;}'
            . '.renew-location-tag.has-tip::after{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 8px);transform:translateX(-50%) translateY(4px);min-width:max-content;max-width:min(26em,70vw);padding:7px 10px;border-radius:8px;background:rgba(17,24,39,0.94);color:#fff;font-size:12px;line-height:1.45;white-space:normal;word-break:break-all;box-shadow:0 10px 24px rgba(15,23,42,0.22);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .16s ease,transform .16s ease;z-index:20;}'
            . '.renew-location-tag.has-tip::before{content:"";position:absolute;left:50%;bottom:calc(100% + 3px);transform:translateX(-50%) translateY(4px);border:5px solid transparent;border-top-color:rgba(17,24,39,0.94);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .16s ease,transform .16s ease;z-index:20;}'
            . '.renew-location-tag.has-tip:hover::after,.renew-location-tag.has-tip:hover::before,.renew-location-tag.has-tip:focus-visible::after,.renew-location-tag.has-tip:focus-visible::before{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);}'
            . '.comment-meta .renew-location-tags{position:relative;top:0;display:inline-flex;vertical-align:middle;}'
            . '</style>';
    }

    public static function footer(object $archive): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1' || !self::hasDisplay($settings)) {
            return;
        }

        if (!method_exists($archive, 'is') || (!$archive->is('single') && !$archive->is('page'))) {
            return;
        }

        $cid = (int) ($archive->cid ?? 0);
        if ($cid <= 0) {
            return;
        }

        $payload = self::payload($cid, $settings);
        if ($payload === []) {
            return;
        }

        $json = Common::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, '{}');

        echo '<script>(function(){var data=' . $json . ';'
            . 'var icons=' . json_encode(self::icons(), JSON_UNESCAPED_SLASHES) . ';'
            . 'var makeTag=function(item){var el=document.createElement("span");el.className="renew-location-tag"+(item.kind?" "+item.kind:"");'
            . 'if(item.tip){el.className+=" has-tip";el.setAttribute("data-tip",item.tip);el.setAttribute("tabindex","0");}'
            . 'var icon=document.createElement("span");icon.className="renew-location-icon";icon.innerHTML=icons[item.icon]||icons.browser;'
            . 'var label=document.createElement("span");label.className="renew-location-label";label.textContent=item.text;'
            . 'el.appendChild(icon);el.appendChild(label);return el;};'
            . 'Object.keys(data).forEach(function(coid){var root=document.getElementById("comment-"+coid)||document.getElementById("li-comment-"+coid)||document.querySelector("[data-coid=\'"+coid+"\']");if(!root){return;}'
            . 'var meta=root.querySelector(".comment-meta");if(!meta||meta.querySelector(".renew-location-tags")){return;}meta.style.overflow="visible";'
            . 'var wrap=document.createElement("span");wrap.className="renew-location-tags";'
            . 'data[coid].forEach(function(item){wrap.appendChild(makeTag(item));});'
            . 'var reply=root.querySelector(".comment-reply");'
            . 'if(reply&&reply.parentNode!==meta){meta.appendChild(reply);}'
            . 'if(reply&&reply.parentNode===meta){meta.insertBefore(wrap,reply);}else{meta.appendChild(wrap);}'
            . '});})();</script>';
    }

    private static function renderTag(array $item): string
    {
        $className = 'renew-location-tag' . ($item['kind'] !== '' ? ' ' . $item['kind'] : '');
        if (!empty($item['tip'])) {
            $className .= ' has-tip';
        }

        $icons = self::icons();
        $icon = $icons[$item['icon'] ?? 'browser'] ?? $icons['browser'];
        $label = htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tip = htmlspecialchars((string) ($item['tip'] ?? ''), ENT_QUOTES, 'UTF-8');

        return '<span class="' . $className . '"' . ($tip !== '' ? ' data-tip="' . $tip . '" tabindex="0"' : '') . '>'
            . '<span class="renew-location-icon">' . $icon . '</span>'
            . '<span class="renew-location-label">' . $label . '</span>'
            . '</span>';
    }

    private static function icons(): array
    {
        return [
            'browser' => '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>',
            'system' => '<svg viewBox="0 0 24 24"><path d="M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z"/></svg>',
            'geo' => '<svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
        ];
    }

    private static function payload(int $cid, array $settings): array
    {
        $db = Db::get();
        $rows = $db->fetchAll($db->select('coid', 'agent', 'ip')
            ->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved'));

        $result = [];
        foreach ($rows as $row) {
            $items = self::items((array) $row, $settings);
            if ($items !== []) {
                $result[(string) ($row['coid'] ?? '')] = $items;
            }
        }

        return $result;
    }

    private static function items(array $row, array $settings): array
    {
        $agent = trim((string) ($row['agent'] ?? ''));
        $needUa = ($settings['showBrowser'] ?? '0') === '1' || ($settings['showSystem'] ?? '0') === '1';
        $info = $needUa ? Ua::parse($agent) : ['browser' => '', 'system' => ''];
        $ip = trim((string) ($row['ip'] ?? ''));
        $items = [];

        if (($settings['showBrowser'] ?? '0') === '1' && $info['browser'] !== '') {
            $items[] = [
                'text' => $info['browser'],
                'kind' => 'is-browser',
                'icon' => 'browser',
            ];
        }
        if (($settings['showSystem'] ?? '0') === '1' && $info['system'] !== '') {
            $items[] = [
                'text' => $info['system'],
                'kind' => 'is-system',
                'icon' => 'system',
            ];
        }

        $location = '';
        if (($settings['showLocation'] ?? '0') === '1') {
            $location = Ip::location($ip);
            if ($location !== '') {
                $items[] = [
                    'text' => self::compactLocation($location),
                    'kind' => 'is-geo',
                    'icon' => 'geo',
                    'tip' => $location,
                ];
            }
        }

        if (($settings['showIpText'] ?? '0') === '1') {
            $masked = Text::maskIp($ip);
            if ($masked !== '' && $location === '') {
                $items[] = ['text' => 'IP ' . $masked, 'kind' => 'is-geo', 'icon' => 'geo'];
            }
        }

        return $items;
    }

    private static function hasDisplay(array $settings): bool
    {
        return ($settings['showBrowser'] ?? '0') === '1'
            || ($settings['showSystem'] ?? '0') === '1'
            || ($settings['showLocation'] ?? '0') === '1'
            || ($settings['showIpText'] ?? '0') === '1';
    }

    private static function compactLocation(string $location): string
    {
        $compact = trim($location);
        if ($compact === '') {
            return '';
        }

        $compact = str_replace(['|', "\t", '—', '–'], [' ', ' ', '-', '-'], $compact);
        $compact = preg_replace('/\s+/', ' ', $compact) ?? $compact;

        $replacements = [
            '中国-' => '',
            '中国 ' => '',
            '中国' => '',
            'IANA 本机地址/环回地址' => '本机/环回',
            'IANA本机地址/环回地址' => '本机/环回',
            '本机地址/环回地址' => '本机/环回',
            '数据上网公共出口' => '',
            '上网公共出口' => '',
            '公共出口' => '',
            '数据中心' => '',
            '无线基站网络' => '',
            '公众宽带' => '',
            '3GNET网络' => '',
            'CMNET网络' => '',
            'CTNET网络' => '',
            '(全省通用)' => '',
            '（全省通用）' => '',
            '局域网IP' => '局域网',
        ];

        $compact = str_replace(array_keys($replacements), array_values($replacements), $compact);
        $compact = str_replace('-', '', $compact);
        $compact = preg_replace('/\s+/', ' ', $compact) ?? $compact;
        $compact = trim($compact, " -\t\n\r\0\x0B");

        if ($compact === '') {
            $compact = $location;
        }

        return Text::plain($compact, 32);
    }
}
