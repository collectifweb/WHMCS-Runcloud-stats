<?php

namespace HubRcVps;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class ServerStats
{
    private $ip;
    private $port;
    private $username;
    private $authMethod; // 'key' or 'password'
    private $keyPath;
    private $password;
    private $timeout;

    public function __construct(string $ip, int $port = 22, int $timeout = 5)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * Configure authentication via SSH key file.
     */
    public function setKeyAuth(string $username, string $keyPath): self
    {
        $this->username = $username;
        $this->keyPath = $keyPath;
        $this->authMethod = 'key';
        return $this;
    }

    /**
     * Configure authentication via password.
     */
    public function setPasswordAuth(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;
        $this->authMethod = 'password';
        return $this;
    }

    /**
     * Fetch all server stats in a single SSH session.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public function fetch(): array
    {
        try {
            $ssh = new SSH2($this->ip, $this->port, $this->timeout);

            if (!$this->authenticate($ssh)) {
                return ['success' => false, 'error' => 'SSH authentication failed'];
            }

            // Run all commands in a single session for efficiency
            $commands = implode(' && echo "---SEPARATOR---" && ', [
                'cat /proc/loadavg',
                'free -m',
                'df -BM /',
                'cat /proc/uptime',
                'ls /home/runcloud/webapps/ 2>/dev/null || echo ""',
            ]);

            $output = $ssh->exec($commands);
            $ssh->disconnect();

            if ($output === false) {
                return ['success' => false, 'error' => 'Failed to execute commands'];
            }

            $parts = explode("---SEPARATOR---\n", $output);
            if (count($parts) < 5) {
                // Try with \r\n
                $parts = explode("---SEPARATOR---\r\n", $output);
            }

            $data = [
                'load' => $this->parseLoadAvg(trim($parts[0] ?? '')),
                'memory' => $this->parseMemory(trim($parts[1] ?? '')),
                'disk' => $this->parseDisk(trim($parts[2] ?? '')),
                'uptime' => $this->parseUptime(trim($parts[3] ?? '')),
                'webapps' => $this->parseWebapps(trim($parts[4] ?? '')),
                'fetched_at' => date('Y-m-d H:i:s'),
            ];

            return ['success' => true, 'data' => $data];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function authenticate(SSH2 $ssh): bool
    {
        if ($this->authMethod === 'key') {
            if (!file_exists($this->keyPath)) {
                return false;
            }
            $key = PublicKeyLoader::load(file_get_contents($this->keyPath));
            return $ssh->login($this->username, $key);
        }

        return $ssh->login($this->username, $this->password);
    }

    /**
     * Parse /proc/loadavg output.
     * Format: "0.08 0.03 0.01 1/123 4567"
     */
    private function parseLoadAvg(string $raw): array
    {
        $parts = preg_split('/\s+/', $raw);
        return [
            'load1' => (float) ($parts[0] ?? 0),
            'load5' => (float) ($parts[1] ?? 0),
            'load15' => (float) ($parts[2] ?? 0),
        ];
    }

    /**
     * Parse `free -m` output.
     * Example:
     *               total        used        free      shared  buff/cache   available
     * Mem:            957         458          49          12         449         498
     * Swap:           ...
     */
    private function parseMemory(string $raw): array
    {
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (strpos($line, 'Mem:') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                return [
                    'total_mb' => (int) ($parts[1] ?? 0),
                    'used_mb' => (int) ($parts[2] ?? 0),
                    'free_mb' => (int) ($parts[3] ?? 0),
                    'available_mb' => (int) ($parts[6] ?? 0),
                    'percent' => ($parts[1] > 0)
                        ? round(($parts[2] / $parts[1]) * 100, 1)
                        : 0,
                ];
            }
        }
        return ['total_mb' => 0, 'used_mb' => 0, 'free_mb' => 0, 'available_mb' => 0, 'percent' => 0];
    }

    /**
     * Parse `df -BM /` output.
     * Example:
     * Filesystem     1M-blocks  Used Available Use% Mounted on
     * /dev/vda1        40586M  8001M   32585M  20% /
     */
    private function parseDisk(string $raw): array
    {
        $lines = explode("\n", $raw);
        if (count($lines) >= 2) {
            $parts = preg_split('/\s+/', trim($lines[1]));
            $total = (int) str_replace('M', '', $parts[1] ?? '0');
            $used = (int) str_replace('M', '', $parts[2] ?? '0');
            $avail = (int) str_replace('M', '', $parts[3] ?? '0');
            $percent = (int) str_replace('%', '', $parts[4] ?? '0');

            return [
                'total_gb' => round($total / 1024, 1),
                'used_gb' => round($used / 1024, 1),
                'available_gb' => round($avail / 1024, 1),
                'percent' => $percent,
            ];
        }
        return ['total_gb' => 0, 'used_gb' => 0, 'available_gb' => 0, 'percent' => 0];
    }

    /**
     * Parse /proc/uptime output.
     * Format: "270847.53 536292.87"
     * First number = uptime in seconds.
     */
    private function parseUptime(string $raw): array
    {
        $seconds = (int) floatval($raw);
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'j';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        $parts[] = $minutes . 'min';

        return [
            'seconds' => $seconds,
            'formatted' => implode(' ', $parts),
        ];
    }

    /**
     * Parse `ls /home/runcloud/webapps/` output.
     * Returns list of webapp directory names.
     */
    private function parseWebapps(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $names = preg_split('/\s+/', trim($raw));
        return array_filter($names, function ($name) {
            return !empty($name);
        });
    }
}
