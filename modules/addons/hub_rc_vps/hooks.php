<?php

/**
 * HUB RunCloud VPS Stats - Hooks
 *
 * Injects VPS server statistics into the product details page.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/vendor/autoload.php';

use WHMCS\Database\Capsule;

add_hook('ClientAreaProductDetailsOutput', 1, function ($params) {
    if (!isset($params['service']) || is_null($params['service'])) {
        return '';
    }

    $service = $params['service'];

    // Get the dedicated IP (and optional port) from the service
    // Supports format "IP" or "IP:PORT"
    $dedicatedIpRaw = trim($service->dedicatedip ?? '');
    $sshPort = 22;
    if (strpos($dedicatedIpRaw, ':') !== false) {
        [$dedicatedIp, $portStr] = explode(':', $dedicatedIpRaw, 2);
        $dedicatedIp = trim($dedicatedIp);
        $sshPort = (int) $portStr;
        if ($sshPort < 1 || $sshPort > 65535) {
            $sshPort = 22;
        }
    } else {
        $dedicatedIp = $dedicatedIpRaw;
    }
    if (empty($dedicatedIp) || !filter_var($dedicatedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }

    // Load module settings
    $settings = [];
    try {
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'hub_rc_vps')
            ->get();
        foreach ($rows as $row) {
            $settings[$row->setting] = $row->value;
        }
    } catch (\Exception $e) {
        return '';
    }

    $sshKeyPath = trim($settings['ssh_key_path'] ?? '');
    $sshUsername = trim($settings['ssh_username'] ?? '') ?: 'root';
    $cacheTtl = (int) ($settings['cache_ttl'] ?? 300);

    // Determine SSH authentication method
    $serverStats = new \HubRcVps\ServerStats($dedicatedIp, $sshPort, 10);

    if (!empty($sshKeyPath) && file_exists($sshKeyPath)) {
        // Key-based auth
        $serverStats->setKeyAuth($sshUsername, $sshKeyPath);
    } else {
        // Fallback: use service username/password
        $svcUsername = trim($service->username ?? '');
        $svcPassword = '';

        // Decrypt the service password
        try {
            $svcPassword = decrypt($service->password ?? '');
        } catch (\Exception $e) {
            // Cannot decrypt
        }

        if (empty($svcUsername) || empty($svcPassword)) {
            return '';
        }

        $serverStats->setPasswordAuth($svcUsername, $svcPassword);
    }

    // Check cache first
    $cache = new \HubRcVps\Cache($cacheTtl);
    $cacheKey = 'vps_stats_' . md5($dedicatedIp);
    $data = $cache->get($cacheKey);

    if ($data === null) {
        $result = $serverStats->fetch();
        if ($result['success']) {
            $data = $result['data'];
            $cache->set($cacheKey, $data);
        } else {
            // Log error for debugging
            $logMsg = date('Y-m-d H:i:s') . " | IP: {$dedicatedIp} | Key: {$sshKeyPath} | User: {$sshUsername} | Error: " . ($result['error'] ?? 'unknown') . "\n";
            @file_put_contents(__DIR__ . '/.debug.log', $logMsg, FILE_APPEND);
            $lang = hub_rc_vps_loadLanguage();
            return hub_rc_vps_renderError($result['error'], $lang);
        }
    }

    // Load language
    $lang = hub_rc_vps_loadLanguage();

    // Render the stats widget
    return hub_rc_vps_renderStats($data, $lang);
});

/**
 * Load language strings based on WHMCS client language.
 * Detection order: session language > client DB language > WHMCS default > english
 */
function hub_rc_vps_loadLanguage(): array
{
    $language = 'english';

    // 1. Session language (set by WHMCS when client switches language)
    if (!empty($_SESSION['Language'])) {
        $language = strtolower($_SESSION['Language']);
    }
    // 2. Client's saved language preference from DB
    elseif (!empty($_SESSION['uid'])) {
        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $_SESSION['uid'])
                ->value('language');
            if (!empty($client)) {
                $language = strtolower($client);
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // Sanitize language name to prevent path traversal
    $language = preg_replace('/[^a-z0-9_-]/', '', $language);
    $langFile = __DIR__ . '/lang/' . $language . '.php';
    if (!$language || !file_exists($langFile)) {
        $langFile = __DIR__ . '/lang/english.php';
    }

    $strings = [];
    include $langFile;
    return $strings;
}

/**
 * Render the stats widget HTML.
 */
function hub_rc_vps_renderStats(array $data, array $lang): string
{
    $load = $data['load'] ?? [];
    $memory = $data['memory'] ?? [];
    $disk = $data['disk'] ?? [];
    $uptime = $data['uptime'] ?? [];
    $webapps = $data['webapps'] ?? [];
    $fetchedAt = $data['fetched_at'] ?? '';

    // Load color indicator
    $loadValue = $load['load1'] ?? 0;
    $loadClass = 'success';
    if ($loadValue > 2) {
        $loadClass = 'danger';
    } elseif ($loadValue > 1) {
        $loadClass = 'warning';
    }

    // Memory bar color
    $memPercent = $memory['percent'] ?? 0;
    $memClass = 'success';
    if ($memPercent > 90) {
        $memClass = 'danger';
    } elseif ($memPercent > 70) {
        $memClass = 'warning';
    }

    // Disk bar color
    $diskPercent = $disk['percent'] ?? 0;
    $diskClass = 'success';
    if ($diskPercent > 90) {
        $diskClass = 'danger';
    } elseif ($diskPercent > 70) {
        $diskClass = 'warning';
    }

    $html = '<style>
.hub-rc-vps-stats .progress { height: 24px; border-radius: 6px; background-color: #e9ecef; margin-top: 6px; margin-bottom: 0; overflow: hidden; }
.hub-rc-vps-stats .progress-bar { line-height: 24px; font-size: 13px; font-weight: 600; border-radius: 6px; min-width: 40px; transition: width .4s ease; }
.hub-rc-vps-stats .gauge-label { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 2px; }
.hub-rc-vps-stats .gauge-label strong { font-size: 14px; }
.hub-rc-vps-stats .gauge-label small { font-size: 12px; color: #888; }
</style>';
    $html .= '<div class="hub-rc-vps-stats" style="margin-top: 20px;">';
    $html .= '<div class="panel panel-default">';
    $html .= '<div class="panel-heading"><h3 class="panel-title"><i class="fas fa-server"></i> ' . ($lang['server_stats'] ?? 'Server Statistics') . '</h3></div>';
    $html .= '<div class="panel-body">';

    // Stats grid
    $html .= '<div class="row">';

    // Left column: Load + Uptime
    $html .= '<div class="col-md-6">';
    $html .= '<table class="table table-condensed">';

    // Load
    $html .= '<tr><td><strong>' . ($lang['load_avg'] ?? 'Load Average') . '</strong></td>';
    $html .= '<td><span class="label label-' . $loadClass . '">';
    $html .= number_format($load['load1'] ?? 0, 2) . '</span> ';
    $html .= '<small class="text-muted">';
    $html .= number_format($load['load5'] ?? 0, 2) . ' / ';
    $html .= number_format($load['load15'] ?? 0, 2);
    $html .= ' <em>(1 / 5 / 15 min)</em></small></td></tr>';

    // Uptime
    $html .= '<tr><td><strong>' . ($lang['uptime'] ?? 'Uptime') . '</strong></td>';
    $html .= '<td>' . htmlspecialchars($uptime['formatted'] ?? 'N/A') . '</td></tr>';

    $html .= '</table>';
    $html .= '</div>';

    // Right column: Memory + Disk bars
    $html .= '<div class="col-md-6">';

    // Memory
    $html .= '<div style="margin-bottom: 18px;">';
    $html .= '<div class="gauge-label"><strong>' . ($lang['memory'] ?? 'Memory') . '</strong>';
    $html .= '<small>' . ($memory['used_mb'] ?? 0) . ' / ' . ($memory['total_mb'] ?? 0) . ' MB</small></div>';
    $html .= '<div class="progress">';
    $html .= '<div class="progress-bar progress-bar-' . $memClass . '" style="width: ' . max($memPercent, 3) . '%;">';
    $html .= round($memPercent) . '%</div></div></div>';

    // Disk
    $html .= '<div>';
    $html .= '<div class="gauge-label"><strong>' . ($lang['disk'] ?? 'Disk') . '</strong>';
    $html .= '<small>' . ($disk['used_gb'] ?? 0) . ' / ' . ($disk['total_gb'] ?? 0) . ' GB</small></div>';
    $html .= '<div class="progress">';
    $html .= '<div class="progress-bar progress-bar-' . $diskClass . '" style="width: ' . max($diskPercent, 3) . '%;">';
    $html .= round($diskPercent) . '%</div></div></div>';

    $html .= '</div>'; // col-md-6
    $html .= '</div>'; // row

    // Webapps list (optional)
    if (!empty($webapps)) {
        $html .= '<hr style="margin: 10px 0;">';
        $html .= '<strong>' . ($lang['webapps'] ?? 'Web Applications') . '</strong>';
        $html .= '<table class="table table-condensed table-striped" style="margin-top: 5px;">';
        $html .= '<thead><tr><th>' . ($lang['webapp_name'] ?? 'Name') . '</th><th>PHP</th></tr></thead><tbody>';
        foreach ($webapps as $app) {
            $name = is_array($app) ? htmlspecialchars($app['name'] ?? '') : htmlspecialchars($app);
            $php = is_array($app) ? htmlspecialchars($app['php'] ?? '') : '';
            $html .= '<tr><td>' . $name . '</td><td><span class="label label-info">' . $php . '</span></td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // Footer
    $html .= '<div class="text-muted text-right" style="margin-top: 10px;">';
    $html .= '<small>' . ($lang['last_updated'] ?? 'Last updated') . ': ' . htmlspecialchars($fetchedAt) . '</small>';
    $html .= '</div>';

    $html .= '</div>'; // panel-body
    $html .= '</div>'; // panel
    $html .= '</div>'; // hub-rc-vps-stats

    return $html;
}

/**
 * Render an error widget.
 */
function hub_rc_vps_renderError(string $error, array $lang = []): string
{
    $msg = $lang['stats_unavailable'] ?? 'Server statistics are temporarily unavailable.';
    $html = '<div class="hub-rc-vps-stats" style="margin-top: 20px;">';
    $html .= '<div class="alert alert-warning">';
    $html .= '<i class="fas fa-exclamation-triangle"></i> ';
    $html .= $msg;
    $html .= ' <small class="text-muted">(' . htmlspecialchars($error) . ')</small>';
    $html .= '</div></div>';
    return $html;
}
