<?php

/**
 * HUB RunCloud VPS Stats - WHMCS Addon Module
 *
 * Displays VPS server statistics (load, memory, disk, uptime) on the
 * client product details page by connecting via SSH to the VPS servers.
 *
 * @author Collectif HUB
 * @version 1.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Module configuration.
 */
function hub_rc_vps_config()
{
    return [
        'name' => 'HUB RunCloud VPS Stats',
        'description' => 'Affiche les statistiques des serveurs VPS (load, mémoire, disque, uptime) sur la page produit du client via SSH.',
        'author' => 'Collectif HUB',
        'language' => 'english',
        'version' => '1.0.0',
        'fields' => [
            'ssh_key_path' => [
                'FriendlyName' => 'SSH Private Key Path',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Chemin absolu vers la clé SSH privée sur le serveur WHMCS (ex: /home/whmcs/.ssh/hub_rc_vps_key). Laissez vide pour utiliser le mot de passe du service.',
            ],
            'ssh_username' => [
                'FriendlyName' => 'SSH Username',
                'Type' => 'text',
                'Size' => '30',
                'Default' => 'root',
                'Description' => 'Username SSH pour connexion aux VPS (utilisé avec l\'authentification par clé).',
            ],
            'cache_ttl' => [
                'FriendlyName' => 'Cache TTL (seconds)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '300',
                'Description' => 'Durée du cache des stats en secondes (défaut: 300 = 5 min).',
            ],
        ],
    ];
}

/**
 * Module activation - creates the cache table.
 */
function hub_rc_vps_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_hub_rc_vps_cache')) {
            Capsule::schema()->create('mod_hub_rc_vps_cache', function ($table) {
                $table->string('cache_key', 191)->primary();
                $table->longText('cache_value');
                $table->integer('expires_at')->unsigned();
            });
        }
        return [
            'status' => 'success',
            'description' => 'Module activé. Configurez le chemin de la clé SSH ou utilisez les credentials des services.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Erreur lors de la création de la table cache : ' . $e->getMessage(),
        ];
    }
}

/**
 * Module deactivation - drops the cache table.
 */
function hub_rc_vps_deactivate()
{
    try {
        Capsule::schema()->dropIfExists('mod_hub_rc_vps_cache');
        return [
            'status' => 'success',
            'description' => 'Module désactivé et cache supprimé.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => $e->getMessage(),
        ];
    }
}

/**
 * Admin area output.
 */
function hub_rc_vps_output($vars)
{
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

    // Handle test connection
    if ($action === 'test') {
        $testIp = isset($_POST['test_ip']) ? trim($_POST['test_ip']) : '';
        $testPort = isset($_POST['test_port']) ? (int) $_POST['test_port'] : 22;

        // Validate IP format to prevent SSRF
        if (!empty($testIp) && filter_var($testIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $stats = new \HubRcVps\ServerStats($testIp, $testPort);

            $sshKeyPath = trim($vars['ssh_key_path']);
            $sshUsername = trim($vars['ssh_username']) ?: 'root';

            if (!empty($sshKeyPath) && file_exists($sshKeyPath)) {
                $stats->setKeyAuth($sshUsername, $sshKeyPath);
            } else {
                echo '<div class="alert alert-danger">Clé SSH non trouvée. Configurez le chemin dans les paramètres du module.</div>';
                return;
            }

            $result = $stats->fetch();

            if ($result['success']) {
                echo '<div class="alert alert-success">Connexion SSH réussie !</div>';
                echo '<pre>' . htmlspecialchars(print_r($result['data'], true)) . '</pre>';
            } else {
                echo '<div class="alert alert-danger">Échec de connexion : ' . htmlspecialchars($result['error']) . '</div>';
            }
            echo '<hr>';
        }
    }

    // Purge expired cache
    if ($action === 'purge_cache') {
        $cache = new \HubRcVps\Cache((int) ($vars['cache_ttl'] ?: 300));
        $cache->purgeExpired();
        echo '<div class="alert alert-success">Cache purgé.</div>';
    }

    // Admin page content
    echo '<h2>HUB RunCloud VPS Stats</h2>';

    // Configuration status
    $sshKeyPath = trim($vars['ssh_key_path']);
    $sshUsername = trim($vars['ssh_username']) ?: 'root';
    $cacheTtl = (int) ($vars['cache_ttl'] ?: 300);

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Configuration actuelle</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed">';
    echo '<tr><td><strong>Clé SSH</strong></td><td>' . ((!empty($sshKeyPath) && file_exists($sshKeyPath)) ? '<span class="label label-success">OK</span> ' . htmlspecialchars($sshKeyPath) : '<span class="label label-warning">Non configurée</span> Les mots de passe des services seront utilisés') . '</td></tr>';
    echo '<tr><td><strong>Username SSH</strong></td><td>' . htmlspecialchars($sshUsername) . '</td></tr>';
    echo '<tr><td><strong>Cache TTL</strong></td><td>' . $cacheTtl . ' secondes</td></tr>';
    echo '</table>';
    echo '</div></div>';

    // Test connection form
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Tester la connexion SSH</h3></div>';
    echo '<div class="panel-body">';
    echo '<form method="post" action="' . $vars['modulelink'] . '&action=test">';
    echo '<div class="form-group"><label>IP du serveur VPS</label>';
    echo '<input type="text" name="test_ip" class="form-control" placeholder="123.456.789.0" style="max-width:300px;"></div>';
    echo '<div class="form-group"><label>Port SSH</label>';
    echo '<input type="text" name="test_port" class="form-control" value="22" style="max-width:100px;"></div>';
    echo '<button type="submit" class="btn btn-primary">Tester</button>';
    echo '</form>';
    echo '</div></div>';

    // Purge cache button
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Maintenance</h3></div>';
    echo '<div class="panel-body">';
    echo '<a href="' . $vars['modulelink'] . '&action=purge_cache" class="btn btn-warning">Purger le cache expiré</a>';
    echo '</div></div>';

    // List services with VPS IPs
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Services VPS avec IP configurée</h3></div>';
    echo '<div class="panel-body">';

    try {
        $services = Capsule::table('tblhosting')
            ->where('dedicatedip', '!=', '')
            ->whereNotNull('dedicatedip')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->join('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
            ->select(
                'tblhosting.id as service_id',
                'tblhosting.domain',
                'tblhosting.dedicatedip',
                'tblhosting.domainstatus',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblproducts.name as product_name'
            )
            ->get();

        if (count($services) > 0) {
            echo '<table class="table table-striped">';
            echo '<thead><tr><th>ID</th><th>Client</th><th>Produit</th><th>Domaine</th><th>IP</th><th>Statut</th></tr></thead>';
            echo '<tbody>';
            foreach ($services as $svc) {
                echo '<tr>';
                echo '<td>' . $svc->service_id . '</td>';
                echo '<td>' . htmlspecialchars($svc->firstname . ' ' . $svc->lastname) . '</td>';
                echo '<td>' . htmlspecialchars($svc->product_name) . '</td>';
                echo '<td>' . htmlspecialchars($svc->domain) . '</td>';
                echo '<td><code>' . htmlspecialchars($svc->dedicatedip) . '</code></td>';
                echo '<td>' . htmlspecialchars($svc->domainstatus) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="text-muted">Aucun service avec une IP dédiée trouvé. Renseignez le champ "Dedicated IP" dans les services VPS.</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="text-danger">Erreur: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

    echo '</div></div>';
}
