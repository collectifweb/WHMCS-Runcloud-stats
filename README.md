# HUB RunCloud VPS Stats

Module WHMCS addon pour [Collectif HUB](https://collectif-hub.ca) qui affiche les statistiques des serveurs VPS directement sur la page produit du portail client.

## Fonctionnalités

- **Load average** (1/5/15 min) avec indicateur couleur
- **Mémoire** : barre de progression + valeurs MB
- **Disque** : barre de progression + valeurs GB
- **Uptime** : formaté en jours/heures/minutes
- **Web Applications** : liste des webapps RunCloud (optionnel)
- Cache DB configurable (défaut 5 min)
- Bilingue (FR/EN)

## Prérequis

- WHMCS 8.x+
- PHP 7.4+
- Accès SSH depuis le serveur WHMCS vers les VPS

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
   - **SSH Private Key Path** : chemin vers la clé SSH privée (optionnel)
   - **SSH Username** : utilisateur SSH (défaut: root)
   - **Cache TTL** : durée du cache en secondes (défaut: 300)

## Configuration SSH

### Option A : Authentification par clé SSH (recommandé)

1. Sur le serveur WHMCS, générer une clé dédiée :
   ```bash
   ssh-keygen -t ed25519 -f /path/to/hub_rc_vps_key -N ""
   ```

2. Sur chaque VPS, ajouter la clé publique :
   ```bash
   cat hub_rc_vps_key.pub >> /root/.ssh/authorized_keys
   ```

3. Configurer le chemin dans le module admin.

### Option B : Authentification par mot de passe

Renseignez le username et le mot de passe SSH dans les champs "Username" et "Password" du service WHMCS du client. Le module les utilisera automatiquement si aucune clé SSH n'est configurée.

## Configuration des services WHMCS

Pour chaque service VPS client, renseignez :
- **Dedicated IP** : l'adresse IP du serveur VPS

Le widget de stats apparaîtra automatiquement sur la page de détails du produit.

## Architecture

```
modules/addons/hub_rc_vps/
├── hub_rc_vps.php      # Module principal (config, admin)
├── hooks.php           # Hook ClientAreaProductDetailsOutput
├── lib/
│   ├── ServerStats.php # Client SSH + parsing stats
│   └── Cache.php       # Cache DB
├── lang/
│   ├── english.php
│   └── french.php
└── vendor/             # phpseclib (composer)
```
