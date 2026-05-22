<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

use Typecho\Plugin as Hook;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Text as TextElement;
use Utils\NoPersonal;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 【TypeRenew 专用】评论归属地插件
 *
 * @package RenewLocation
 * @author TypeRenew
 * @link https://www.typerenew.com/
 * @version 1.0.0
 * @since 1.5.0
 */
class Plugin implements PluginInterface
{
    use NoPersonal;

    public static function activate(): string
    {
        Settings::ensureStored();
        self::registerHooks();
        Helper::removeRoute('renew_location_action');
        Helper::addRoute('renew_location_action', '/action/renew-location', Action::class, 'action');
        
        $dataDir = Settings::dataRoot();
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        return _t('RenewLocation 已启用');
    }

    public static function deactivate(): string
    {
        Helper::removeRoute('renew_location_action');
        Settings::clear();
        return _t('RenewLocation 已停用');
    }

    public static function config(Form $form): void
    {
        $settings = Settings::load();

        $enabled = new Form\Element\Radio(
            'enabled',
            ['1' => _t('启用'), '0' => _t('停用')],
            $settings['enabled'] ?? '1',
            _t('插件状态'),
            _t('启用后会在前台评论区输出 UA 与 IP 归属地信息。')
        );
        $form->addInput($enabled);

        $display = new Checkbox(
            'display',
            [
                'browser' => _t('显示浏览器'),
                'system' => _t('显示操作系统'),
                'location' => _t('显示归属地（优先）'),
                'ip' => _t('显示脱敏 IP（兜底）'),
            ],
            self::displayValues($settings),
            _t('前台显示项'),
            _t('默认显示浏览器、操作系统、归属地；若同时开启"归属地"和"脱敏 IP"，前台优先显示归属地；仅在解析失败时才显示脱敏 IP')
        );
        $form->addInput($display->multiMode());

        $themeCode = new Textarea(
            'themeCode',
            null,
            "<?php if (class_exists('\\TypechoPlugin\\RenewLocation\\View')) { \\TypechoPlugin\\RenewLocation\\View::renderComment(\$comments ?? \$this); } ?>",
            _t('第三方模板调用示例'),
            _t('默认/兼容模板自动注入显示；若使用第三方模板，且评论区未自动显示 UA / 归属地，请在评论循环中选择一个合适位置手动放置上面这段代码；该示例已兼容常见的 "$comments" / "$this" 两种评论变量，调用位置建议放在评论时间后、回复按钮前')
        );
        $themeCode->setInputsAttribute('readonly', 'readonly');
        $themeCode->setInputsAttribute('spellcheck', 'false');
        $form->addInput($themeCode);

        $autoUpdate = new Form\Element\Radio(
            'autoUpdate',
            ['1' => _t('启用'), '0' => _t('关闭')],
            (string) ($settings['autoUpdate'] ?? '1'),
            _t('自动更新'),
            _t('保存后，插件会在前台或后台访问时按周期自动检查并更新 IP 库。')
        );
        $form->addInput($autoUpdate);

        $updateUrl = new TextElement(
            'updateUrl',
            null,
            (string) ($settings['updateUrl'] ?? ''),
            _t('数据库下载地址'),
            _t('填写纯真提供的 HTTPS "czdb.zip" 下载地址。')
        );
        $form->addInput($updateUrl);

        $accessKey = new TextElement(
            'accessKey',
            null,
            (string) ($settings['accessKey'] ?? ''),
            _t('访问密钥'),
            _t('用于本地解密和查询 CZDB 数据库。')
        );
        $form->addInput($accessKey);

        $updateDays = new TextElement(
            'updateDays',
            null,
            (string) ($settings['updateDays'] ?? 7),
            _t('更新周期'),
            _t('单位为天，建议保持 "7"。')
        );
        $form->addInput($updateDays);

        $timeout = new TextElement(
            'timeout',
            null,
            (string) ($settings['timeout'] ?? 8),
            _t('请求超时'),
            _t('单位为秒，建议 5-10 秒。')
        );
        $form->addInput($timeout);
    }

    public static function configHandle(array $settings, bool $_isInit): void
    {
        $current = Settings::load();
        $display = array_map('strval', (array) ($settings['display'] ?? []));
        $next = array_merge($current, [
            'enabled' => (string) ($settings['enabled'] ?? ($current['enabled'] ?? '1')),
            'showSystem' => in_array('system', $display, true) ? '1' : '0',
            'showBrowser' => in_array('browser', $display, true) ? '1' : '0',
            'showLocation' => in_array('location', $display, true) ? '1' : '0',
            'showIpText' => in_array('ip', $display, true) ? '1' : '0',
            'autoUpdate' => (string) ($settings['autoUpdate'] ?? ($current['autoUpdate'] ?? '1')),
            'updateUrl' => (string) ($settings['updateUrl'] ?? ''),
            'accessKey' => (string) ($settings['accessKey'] ?? ''),
            'updateDays' => (string) ($settings['updateDays'] ?? 7),
            'timeout' => (string) ($settings['timeout'] ?? 8),
        ]);
        Settings::store($next);
    }

    private static function registerHooks(): void
    {
        Hook::factory('Widget\\Archive')->{'header_35'} = [View::class, 'header'];
        Hook::factory('Widget\\Archive')->{'footer_35'} = [View::class, 'footer'];
        Hook::factory('index.php')->end = [Action::class, 'triggerUpdate'];
        Hook::factory('admin/footer.php')->end = [Action::class, 'triggerUpdate'];
    }

    private static function displayValues(array $settings): array
    {
        $values = [];
        if (($settings['showSystem'] ?? '0') === '1') {
            $values[] = 'system';
        }
        if (($settings['showBrowser'] ?? '0') === '1') {
            $values[] = 'browser';
        }
        if (($settings['showLocation'] ?? '0') === '1') {
            $values[] = 'location';
        }
        if (($settings['showIpText'] ?? '0') === '1') {
            $values[] = 'ip';
        }
        return $values;
    }
}
