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

    // Get the dedicated IP from the service
    $dedicatedIp = trim($service->dedicatedip ?? '');
    if (empty($dedicatedIp)) {
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
    $serverStats = new \HubRcVps\ServerStats($dedicatedIp, 22);

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
            // Return error widget
            return hub_rc_vps_renderError($result['error']);
        }
    }

    // Load language
    $lang = hub_rc_vps_loadLanguage();

    // Render the stats widget
    return hub_rc_vps_renderStats($data, $lang);
});

/**
 * Load language strings based on current session language.
 */
function hub_rc_vps_loadLanguage(): array
{
    $language = isset($_SESSION['Language']) ? strtolower($_SESSION['Language']) : 'english';
    $langFile = __DIR__ . '/lang/' . $language . '.php';

    if (!file_exists($langFile)) {
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

    $html = '<div class="hub-rc-vps-stats" style="margin-top: 20px;">';
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
    $html .= '<div style="margin-bottom: 15px;">';
    $html .= '<strong>' . ($lang['memory'] ?? 'Memory') . '</strong>';
    $html .= ' <small class="text-muted">' . ($memory['used_mb'] ?? 0) . ' / ' . ($memory['total_mb'] ?? 0) . ' MB</small>';
    $html .= '<div class="progress" style="margin-top: 5px; margin-bottom: 0;">';
    $html .= '<div class="progress-bar progress-bar-' . $memClass . '" style="width: ' . $memPercent . '%;">';
    $html .= round($memPercent) . '%</div></div></div>';

    // Disk
    $html .= '<div>';
    $html .= '<strong>' . ($lang['disk'] ?? 'Disk') . '</strong>';
    $html .= ' <small class="text-muted">' . ($disk['used_gb'] ?? 0) . ' / ' . ($disk['total_gb'] ?? 0) . ' GB</small>';
    $html .= '<div class="progress" style="margin-top: 5px; margin-bottom: 0;">';
    $html .= '<div class="progress-bar progress-bar-' . $diskClass . '" style="width: ' . $diskPercent . '%;">';
    $html .= round($diskPercent) . '%</div></div></div>';

    $html .= '</div>'; // col-md-6
    $html .= '</div>'; // row

    // Webapps list (optional)
    if (!empty($webapps)) {
        $html .= '<hr style="margin: 10px 0;">';
        $html .= '<strong>' . ($lang['webapps'] ?? 'Web Applications') . '</strong>';
        $html .= '<div style="margin-top: 5px;">';
        foreach ($webapps as $app) {
            $html .= '<span class="label label-default" style="margin-right: 5px; margin-bottom: 3px; display: inline-block;">' . htmlspecialchars($app) . '</span>';
        }
        $html .= '</div>';
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
function hub_rc_vps_renderError(string $error): string
{
    $html = '<div class="hub-rc-vps-stats" style="margin-top: 20px;">';
    $html .= '<div class="alert alert-warning">';
    $html .= '<i class="fas fa-exclamation-triangle"></i> ';
    $html .= 'Les statistiques du serveur sont temporairement indisponibles.';
    $html .= ' <small class="text-muted">(' . htmlspecialchars($error) . ')</small>';
    $html .= '</div></div>';
    return $html;
}
