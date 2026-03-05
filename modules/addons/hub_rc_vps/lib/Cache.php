<?php

namespace HubRcVps;

use WHMCS\Database\Capsule;

class Cache
{
    private $ttl;
    private $tableName = 'mod_hub_rc_vps_cache';

    public function __construct(int $ttl = 300)
    {
        $this->ttl = $ttl;
    }

    public function get(string $key): ?array
    {
        try {
            $row = Capsule::table($this->tableName)
                ->where('cache_key', $key)
                ->where('expires_at', '>', time())
                ->first();

            if ($row) {
                return json_decode($row->cache_value, true);
            }
        } catch (\Exception $e) {
            // Table might not exist; fail silently
        }

        return null;
    }

    public function set(string $key, array $data): void
    {
        try {
            Capsule::table($this->tableName)->updateOrInsert(
                ['cache_key' => $key],
                [
                    'cache_value' => json_encode($data),
                    'expires_at' => time() + $this->ttl,
                ]
            );
        } catch (\Exception $e) {
            // Caching is non-critical; fail silently
        }
    }

    public function delete(string $key): void
    {
        try {
            Capsule::table($this->tableName)
                ->where('cache_key', $key)
                ->delete();
        } catch (\Exception $e) {
            // Ignore
        }
    }

    public function purgeExpired(): void
    {
        try {
            Capsule::table($this->tableName)
                ->where('expires_at', '<', time())
                ->delete();
        } catch (\Exception $e) {
            // Ignore
        }
    }
}
