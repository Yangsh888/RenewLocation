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
    private const MAX_DOWNLOAD_BYTES = 134217728;
    private const MAX_ZIP_ENTRIES = 64;
    private const MAX_ENTRY_BYTES = 134217728;
    private const MAX_EXTRACTED_BYTES = 268435456;
    private const MAX_COMPRESSION_RATIO = 200;

    public function action(): void
    {
        $do = $this->request->get('do');

        if ($do === 'update') {
            if (!$this->request->isPost()) {
                $this->response->setStatus(405);
                $this->response->throwJson(['success' => false, 'message' => 'Method Not Allowed']);
            }

            $settings = Settings::load();
            $token = trim((string) $this->request->get('token'));
            $secret = (string) ($settings['updateSecret'] ?? '');
            if ($secret === '' || !Common::timeTokenValidate($token, $secret, 30)) {
                $this->response->throwJson(['success' => false, 'message' => 'Invalid update token']);
            }
            $this->response->throwJson($this->handleUpdate());
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
                $out = "POST " . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') . " HTTP/1.1\r\n";
                $out .= "Host: " . $parts['host'] . "\r\n";
                $out .= "Content-Length: 0\r\n";
                $out .= "Connection: Close\r\n\r\n";
                fwrite($fp, $out);
                fclose($fp);
            }
        }
    }

    private function handleUpdate(): array
    {
        @set_time_limit(300);

        $settings = Settings::load();
        $url = trim((string) ($settings['updateUrl'] ?? ''));
        if ($url === '') {
            return ['success' => false, 'message' => 'Empty update URL'];
        }

        $timeout = (int) ($settings['timeout'] ?? 8);
        $dataDir = Settings::dataRoot();
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            return ['success' => false, 'message' => 'Failed to create data directory'];
        }

        try {
            $lock = $this->acquireUpdateLock($dataDir);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $stagingDir = '';
        $expectedFiles = [
            'cz88_public_v4.czdb',
            'cz88_public_v6.czdb',
        ];
        $stagedFiles = [];
        $backupFiles = [];
        $replacedTargets = [];
        $createdTargets = [];
        $zip = null;
        $preserveStaging = false;

        try {
            $stagingDir = $dataDir . DIRECTORY_SEPARATOR . '.update-' . bin2hex(Common::secureRandomBytes(4));
            if (!@mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
                throw new \RuntimeException('Failed to create staging directory');
            }

            $tmpFile = $stagingDir . DIRECTORY_SEPARATOR . 'czdb.zip';
            $this->download($url, $tmpFile, max(5, $timeout));

            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive extension is missing');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                throw new \RuntimeException('Failed to open downloaded ZIP file');
            }

            $entries = $this->validateZip($zip, $expectedFiles);
            foreach ($entries as $filename => $entry) {
                $dest = $stagingDir . DIRECTORY_SEPARATOR . $filename;
                $this->extractEntry($zip, $entry, $dest);
                $stagedFiles[$filename] = $dest;
            }
            $zip->close();
            $zip = null;

            @unlink($tmpFile);

            foreach ($expectedFiles as $filename) {
                if (!isset($stagedFiles[$filename])) {
                    throw new \RuntimeException('Missing required database file: ' . $filename);
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
                    throw new \RuntimeException('Failed to create backup for ' . $filename);
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

            return [
                'success' => true,
                'message' => 'Updated successfully',
                'extracted' => count($stagedFiles),
            ];
        } catch (\Throwable $e) {
            foreach (array_reverse($replacedTargets) as $target) {
                $backup = $backupFiles[$target] ?? '';
                if ($backup !== '' && is_file($backup)) {
                    try {
                        $this->replaceFile($backup, $target);
                    } catch (\Throwable $rollbackError) {
                        $preserveStaging = true;
                        error_log('RenewLocation.rollback: ' . $rollbackError->getMessage());
                    }
                } elseif (!empty($createdTargets[$target]) && is_file($target)) {
                    if (!@unlink($target)) {
                        $preserveStaging = true;
                        error_log('RenewLocation.rollback: failed to remove ' . basename($target));
                    }
                }
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($zip instanceof \ZipArchive) {
                $zip->close();
            }
            if (!$preserveStaging) {
                $this->cleanupStaging($stagingDir);
            }
            $this->releaseUpdateLock($lock);
        }
    }

    private function acquireUpdateLock(string $dataDir)
    {
        $path = $dataDir . DIRECTORY_SEPARATOR . '.update.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open update lock');
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another database update is already running');
        }

        return $handle;
    }

    private function releaseUpdateLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function download(string $url, string $target, int $timeout, int $maxBytes = self::MAX_DOWNLOAD_BYTES): void
    {
        $current = $url;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'user_agent' => 'TypeRenew RenewLocation',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);
            $source = @fopen($current, 'rb', false, $context);
            if ($source === false) {
                throw new \RuntimeException('Failed to download IP database');
            }

            $meta = stream_get_meta_data($source);
            [$status, $location, $contentLength] = $this->responseMeta((array) ($meta['wrapper_data'] ?? []));
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                fclose($source);
                if ($redirects >= 3 || $location === '') {
                    throw new \RuntimeException('Too many or invalid download redirects');
                }
                $current = $this->redirectUrl($current, $location);
                continue;
            }

            if ($status !== 0 && ($status < 200 || $status >= 300)) {
                fclose($source);
                throw new \RuntimeException('Download server returned HTTP ' . $status);
            }
            if ($contentLength > $maxBytes) {
                fclose($source);
                throw new \RuntimeException('Downloaded ZIP exceeds size limit');
            }

            $output = @fopen($target, 'wb');
            if ($output === false) {
                fclose($source);
                throw new \RuntimeException('Failed to create downloaded ZIP file');
            }

            $written = 0;
            $complete = false;
            try {
                while (!feof($source)) {
                    $chunk = fread($source, 8192);
                    if ($chunk === false) {
                        throw new \RuntimeException('Failed while reading downloaded ZIP');
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        throw new \RuntimeException('Downloaded ZIP exceeds size limit');
                    }
                    $this->writeAll($output, $chunk);
                }
                $complete = true;
            } finally {
                fclose($source);
                fclose($output);
                if (!$complete) {
                    @unlink($target);
                }
            }

            if ($written <= 0) {
                @unlink($target);
                throw new \RuntimeException('Downloaded ZIP is empty');
            }
            return;
        }

        throw new \RuntimeException('Download redirect limit exceeded');
    }

    private function responseMeta(array $headers): array
    {
        $status = 0;
        $location = '';
        $contentLength = 0;
        foreach ($headers as $header) {
            $header = trim((string) $header);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
                continue;
            }
            if (stripos($header, 'Content-Length:') === 0) {
                $contentLength = max(0, (int) trim(substr($header, 15)));
            }
        }

        return [$status, $location, $contentLength];
    }

    private function redirectUrl(string $current, string $location): string
    {
        if (str_starts_with($location, '//')) {
            $location = 'https:' . $location;
        } elseif (str_starts_with($location, '/')) {
            $parts = parse_url($current);
            if (!is_array($parts) || empty($parts['host'])) {
                throw new \RuntimeException('Invalid download redirect');
            }
            $location = 'https://' . $parts['host']
                . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . $location;
        }

        if (!filter_var($location, FILTER_VALIDATE_URL) || strtolower((string) parse_url($location, PHP_URL_SCHEME)) !== 'https') {
            throw new \RuntimeException('Unsafe download redirect');
        }

        return $location;
    }

    private function validateZip(\ZipArchive $zip, array $expectedFiles): array
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw new \RuntimeException('ZIP entry count exceeds limit');
        }

        $total = 0;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new \RuntimeException('Failed to inspect ZIP entry');
            }

            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $size = max(0, (int) ($stat['size'] ?? 0));
            $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new \RuntimeException('ZIP entry exceeds size limit');
            }

            $total += $size;
            if ($total > self::MAX_EXTRACTED_BYTES) {
                throw new \RuntimeException('ZIP extracted size exceeds limit');
            }
            if ($size > 0 && ($compressed === 0 || $size > $compressed * self::MAX_COMPRESSION_RATIO)) {
                throw new \RuntimeException('ZIP entry compression ratio exceeds limit');
            }

            $filename = basename($name);
            if (!in_array($filename, $expectedFiles, true)) {
                continue;
            }
            if (isset($entries[$filename])) {
                throw new \RuntimeException('Duplicate required database file: ' . $filename);
            }
            if ($size < 1024) {
                throw new \RuntimeException('Database file is unexpectedly small: ' . $filename);
            }

            $entries[$filename] = [
                'name' => $name,
                'size' => $size,
            ];
        }

        foreach ($expectedFiles as $filename) {
            if (!isset($entries[$filename])) {
                throw new \RuntimeException('Missing required database file: ' . $filename);
            }
        }

        return $entries;
    }

    private function extractEntry(\ZipArchive $zip, array $entry, string $target): void
    {
        $source = $zip->getStream((string) $entry['name']);
        if ($source === false) {
            throw new \RuntimeException('Failed to read ' . basename((string) $entry['name']));
        }

        $output = @fopen($target, 'xb');
        if ($output === false) {
            fclose($source);
            throw new \RuntimeException('Failed to stage ' . basename($target));
        }

        $written = 0;
        $complete = false;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed while extracting ' . basename($target));
                }
                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);
                if ($written > self::MAX_ENTRY_BYTES) {
                    throw new \RuntimeException('Extracted database file exceeds size limit');
                }
                $this->writeAll($output, $chunk);
            }

            if ($written !== (int) $entry['size']) {
                throw new \RuntimeException('Extracted database file size mismatch');
            }
            $complete = true;
        } finally {
            fclose($source);
            fclose($output);
            if (!$complete) {
                @unlink($target);
            }
        }
    }

    private function writeAll($handle, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write update file');
            }
            $offset += $written;
        }
    }

    private function replaceFile(string $source, string $target): void
    {
        if (!is_file($source)) {
            throw new \RuntimeException('Staged file is missing: ' . basename($source));
        }

        if (@rename($source, $target)) {
            return;
        }

        $backup = $target . '.old';
        if (file_exists($backup)) {
            @unlink($backup);
        }

        if (file_exists($target) && !@rename($target, $backup)) {
            throw new \RuntimeException('Failed to move current file aside: ' . basename($target));
        }

        if (!@rename($source, $target)) {
            if (file_exists($backup)) {
                @rename($backup, $target);
            }

            throw new \RuntimeException('Failed to replace file: ' . basename($target));
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
