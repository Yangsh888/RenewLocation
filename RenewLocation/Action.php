<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

use Typecho\Common;
use Typecho\Widget;
use Utils\Helper;

use Widget\ActionInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    public function action()
    {
        $do = $this->request->get('do');

        if ($do === 'update') {
            $settings = Settings::load();
            $token = trim((string) $this->request->get('token'));
            $secret = (string) ($settings['updateSecret'] ?? '');
            if ($secret === '' || !Common::timeTokenValidate($token, $secret, 30)) {
                $this->response->throwJson(['success' => false, 'message' => 'Invalid update token']);
            }
            $this->handleUpdate();
        }
    }

    public static function triggerUpdate(): void
    {
        $settings = Settings::load();
        if (($settings['enabled'] ?? '0') !== '1' || ($settings['autoUpdate'] ?? '0') !== '1') {
            return;
        }

        $url = trim((string) ($settings['updateUrl'] ?? ''));
        if ($url === '') {
            return;
        }

        $now = time();
        $lastDispatch = (int) ($settings['lastDispatchAt'] ?? 0);
        if ($now - $lastDispatch < 3600) {
            return;
        }

        $lastUpdate = (int) ($settings['lastUpdateAt'] ?? 0);
        $days = (int) ($settings['updateDays'] ?? 7);
        $days = max(1, $days);

        if ($now - $lastUpdate < $days * 86400) {
            return;
        }

        $settings['lastDispatchAt'] = $now;
        Settings::store($settings);

        $triggerUrl = Settings::actionUrl('update');
        $parts = parse_url($triggerUrl);
        if (is_array($parts) && isset($parts['host'])) {
            $fp = @fsockopen(
                (isset($parts['scheme']) && $parts['scheme'] === 'https' ? 'ssl://' : '') . $parts['host'],
                $parts['port'] ?? (isset($parts['scheme']) && $parts['scheme'] === 'https' ? 443 : 80),
                $errno,
                $errstr,
                1
            );
            if ($fp) {
                $out = "GET " . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') . " HTTP/1.1\r\n";
                $out .= "Host: " . $parts['host'] . "\r\n";
                $out .= "Connection: Close\r\n\r\n";
                fwrite($fp, $out);
                fclose($fp);
            }
        }
    }

    private function handleUpdate(): void
    {
        @set_time_limit(300);

        $settings = Settings::load();
        $url = trim((string) ($settings['updateUrl'] ?? ''));
        if ($url === '') {
            $this->response->throwJson(['success' => false, 'message' => 'Empty update URL']);
        }

        $timeout = (int) ($settings['timeout'] ?? 8);
        $contextOptions = [
            'http' => [
                'timeout' => max(5, $timeout),
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];

        $context = stream_context_create($contextOptions);

        $dataDir = Settings::dataRoot();
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }

        $stagingDir = $dataDir . DIRECTORY_SEPARATOR . '.update-' . bin2hex(Common::secureRandomBytes(4));
        if (!@mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
            $this->response->throwJson(['success' => false, 'message' => 'Failed to create staging directory']);
        }

        $tmpFile = $stagingDir . DIRECTORY_SEPARATOR . 'czdb.zip';
        $expectedFiles = [
            'cz88_public_v4.czdb',
            'cz88_public_v6.czdb',
        ];
        $stagedFiles = [];
        $backupFiles = [];
        $replacedTargets = [];
        $createdTargets = [];

        try {
            $result = @copy($url, $tmpFile, $context);
            if (!$result) {
                throw new \Exception('Failed to download IP database');
            }

            if (!class_exists('ZipArchive')) {
                throw new \Exception('ZipArchive extension is missing');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                throw new \Exception('Failed to open downloaded ZIP file');
            }

            $extracted = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = basename((string) $zip->getNameIndex($i));
                if (!in_array($filename, $expectedFiles, true)) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    continue;
                }

                $dest = $stagingDir . DIRECTORY_SEPARATOR . $filename;
                if (file_put_contents($dest, $content) === false) {
                    throw new \Exception('Failed to stage ' . $filename);
                }

                $stagedFiles[$filename] = $dest;
                $extracted++;
            }
            $zip->close();

            @unlink($tmpFile);

            foreach ($expectedFiles as $filename) {
                if (!isset($stagedFiles[$filename])) {
                    throw new \Exception('Missing required database file: ' . $filename);
                }
            }

            foreach ($expectedFiles as $filename) {
                $target = $dataDir . DIRECTORY_SEPARATOR . $filename;
                if (!is_file($target)) {
                    $createdTargets[$target] = true;
                    continue;
                }

                $backup = $stagingDir . DIRECTORY_SEPARATOR . $filename . '.bak';
                if (!@copy($target, $backup)) {
                    throw new \Exception('Failed to create backup for ' . $filename);
                }

                $backupFiles[$target] = $backup;
            }

            foreach ($expectedFiles as $filename) {
                $target = $dataDir . DIRECTORY_SEPARATOR . $filename;
                $this->replaceFile($stagedFiles[$filename], $target);
                $replacedTargets[] = $target;
            }

            $settings['lastUpdateAt'] = time();
            Settings::store($settings);

            $this->cleanupStaging($stagingDir);
            $this->response->throwJson(['success' => true, 'message' => 'Updated successfully', 'extracted' => $extracted]);
        } catch (\Throwable $e) {
            foreach (array_reverse($replacedTargets) as $target) {
                $backup = $backupFiles[$target] ?? '';
                if ($backup !== '' && is_file($backup)) {
                    try {
                        $this->replaceFile($backup, $target);
                    } catch (\Throwable) {
                    }
                } elseif (!empty($createdTargets[$target]) && is_file($target)) {
                    @unlink($target);
                }
            }

            $this->cleanupStaging($stagingDir);
            Settings::store($settings);
            $this->response->throwJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function replaceFile(string $source, string $target): void
    {
        if (!is_file($source)) {
            throw new \Exception('Staged file is missing: ' . basename($source));
        }

        if (@rename($source, $target)) {
            return;
        }

        $backup = $target . '.old';
        if (file_exists($backup)) {
            @unlink($backup);
        }

        if (file_exists($target) && !@rename($target, $backup)) {
            throw new \Exception('Failed to move current file aside: ' . basename($target));
        }

        if (!@rename($source, $target)) {
            if (file_exists($backup)) {
                @rename($backup, $target);
            }

            throw new \Exception('Failed to replace file: ' . basename($target));
        }

        if (file_exists($backup)) {
            @unlink($backup);
        }
    }

    private function cleanupStaging(string $stagingDir): void
    {
        if (!is_dir($stagingDir)) {
            return;
        }

        foreach ((array) glob($stagingDir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($stagingDir);
    }
}
