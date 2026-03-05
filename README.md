# HUB RunCloud VPS Stats

Module WHMCS addon pour [Collectif HUB](https://collectif-hub.ca) qui affiche les statistiques des serveurs VPS directement sur la page produit du portail client.

## Fonctionnalités

- **Load average** (1/5/15 min) avec indicateur couleur
- **Mémoire** : barre de progression + valeurs MB
- **Disque** : barre de progression + valeurs GB
- **Uptime** : formaté en jours/heures/minutes
- **Web Applications** : liste des webapps RunCloud avec version PHP
- Cache DB configurable (défaut 5 min)
- Bilingue (FR/EN)

## Prérequis

- WHMCS 8.x+
- PHP 7.4+
- Accès SSH depuis le serveur WHMCS vers les VPS
- Serveurs VPS gérés par RunCloud

## Installation

1. Copier le dossier `modules/addons/hub_rc_vps/` dans votre installation WHMCS :
   ```
   /your-whmcs/modules/addons/hub_rc_vps/
   ```

2. Installer les dépendances :
   ```bash
   cd /your-whmcs/modules/addons/hub_rc_vps/
   composer install --no-dev
   ```

3. Activer le module dans WHMCS Admin :
   - Setup > Addon Modules > HUB RunCloud VPS Stats > Activate

4. Configurer le module :
   - **SSH Private Key Path** : chemin vers la clé SSH privée sur le serveur WHMCS
   - **SSH Username** : utilisateur SSH (recommandé : `runcloud`)
   - **Cache TTL** : durée du cache en secondes (défaut : 300)

## Configuration SSH

### 1. Générer la clé SSH sur le serveur WHMCS

```bash
ssh-keygen -t ed25519 -f /path/to/hub_rc_vps_key -N "" -C "hub-rc-vps-whmcs"
```

> **Important (open_basedir)** : Si WHMCS tourne sous PHP-FPM avec `open_basedir`, la clé SSH doit se trouver dans un répertoire autorisé. On recommande de la placer directement dans le dossier du module en tant que fichier caché :
> ```
> /your-whmcs/modules/addons/hub_rc_vps/.ssh_key
> ```
> Les fichiers commençant par `.` sont automatiquement bloqués par nginx-rc (retourne 403), donc la clé n'est pas accessible via le web.

### 2. Ajouter la clé publique sur chaque VPS via RunCloud

Dans RunCloud > Server > SSH > SSH Key > **Add New** :
- **Label** : `WHMCS`
- **User** : `runcloud` (recommandé, évite les problèmes de `PermitRootLogin`)
- Coller le contenu de la clé publique (`.pub`)

### 3. Configurer le module WHMCS

Dans WHMCS Admin > Setup > Addon Modules > HUB RunCloud VPS Stats :
- **SSH Private Key Path** : `/your-whmcs/modules/addons/hub_rc_vps/.ssh_key`
- **SSH Username** : `runcloud`

### Pourquoi `runcloud` et pas `root` ?

- Certains serveurs RunCloud ont `PermitRootLogin` désactivé par défaut
- Le user `runcloud` a accès à toutes les infos nécessaires (`/proc/loadavg`, `free`, `df`, `/proc/uptime`, configs FPM)
- Moins de risque d'être bloqué par les politiques de sécurité SSH

## Sécurité

### Restriction par IP (recommandé)

On peut restreindre la clé SSH pour qu'elle ne soit acceptée que depuis l'IP du serveur WHMCS. Sur chaque VPS, dans `/home/runcloud/.ssh/authorized_keys`, préfixer la clé avec :

```
from="IP_WHMCS",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA... hub-rc-vps-whmcs
```

> **Note** : RunCloud peut écraser le fichier `authorized_keys` lors de modifications via son interface. Vérifier après chaque modification de clé dans RunCloud.

### Protections intégrées

- **SSRF** : les IP privées et réservées sont rejetées (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`)
- **XSS** : toutes les sorties sont échappées avec `htmlspecialchars()`
- **Path traversal** : le nom de langue est sanitisé (`preg_replace('/[^a-z0-9_-]/', '', $language)`)
- **Fichiers sensibles** : `.ssh_key` et `.debug.log` sont des dotfiles, bloqués par nginx-rc
- **SQL injection** : utilisation de Capsule ORM (requêtes paramétrées)

## Configuration des services WHMCS

Pour chaque service VPS client, renseignez le champ **Dedicated IP** avec l'adresse IP du serveur VPS. Le widget de stats apparaîtra automatiquement sur la page de détails du produit.

## Dépannage

### "SSH authentication failed"

1. Vérifier que la clé WHMCS est bien ajoutée au user `runcloud` (pas `root`) dans RunCloud > SSH
2. Vérifier que le **SSH Username** dans les settings du module WHMCS est bien `runcloud`
3. Vérifier que `Prevent root login` n'est pas activé si vous utilisez le user `root`
4. Purger le cache depuis l'admin du module

### "Connection refused"

1. Vérifier que le port 22 est ouvert dans RunCloud > Security > Firewall (type Global)
2. Vérifier que **fail2ban** n'a pas banni l'IP du serveur WHMCS (des tentatives échouées peuvent causer un ban)
3. Pour débanner : `fail2ban-client unban IP_WHMCS` sur le VPS concerné

### "Password change required"

Certains serveurs demandent un changement de mot de passe root à la première connexion. Se connecter manuellement une fois pour régler le problème, ou utiliser le user `runcloud` à la place.

## Architecture

```
modules/addons/hub_rc_vps/
├── hub_rc_vps.php      # Module principal (config, admin, test SSH)
├── hooks.php           # Hook ClientAreaProductDetailsOutput + rendu HTML
├── lib/
│   ├── ServerStats.php # Client SSH (phpseclib) + parsing stats système
│   └── Cache.php       # Cache DB (table mod_hub_rc_vps_cache)
├── lang/
│   ├── english.php     # Traductions anglaises
│   └── french.php      # Traductions françaises
├── .ssh_key            # Clé SSH privée (non commité, protégé par nginx)
├── .debug.log          # Log de debug (non commité, protégé par nginx)
└── vendor/             # phpseclib (composer)
```

## Détection des webapps RunCloud

Le module détecte les webapps en scannant les configs FPM pool de RunCloud :
```
/etc/php*rc/fpm.d/app-*.conf
```
Format extrait : `app-name|phpversion` (ex: `app-monsite|82` → PHP 8.2)

## Licence

Propriétaire - Collectif HUB
