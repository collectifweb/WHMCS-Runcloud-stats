# HUB RunCloud VPS Stats

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![WHMCS](https://img.shields.io/badge/WHMCS-8.x%2B-blue)](https://www.whmcs.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)](https://www.php.net/)

WHMCS addon module that displays live VPS server statistics (load, memory, disk, uptime, web apps) directly on the client product details page, by connecting to the VPS over SSH.

Originally built for [Collectif HUB](https://collectif-hub.ca) and released as open source for the WHMCS / RunCloud community.

> Module addon WHMCS qui affiche les statistiques temps réel des serveurs VPS (charge, mémoire, disque, uptime, applications web) directement sur la page produit du client, via une connexion SSH.

---

## Features / Fonctionnalités

- **Load average** (1 / 5 / 15 min) with color indicator
- **Memory** progress bar + MB values
- **Disk** progress bar + GB values
- **Uptime** formatted (days / hours / minutes)
- **Web Applications** list with PHP version (RunCloud-aware)
- Configurable DB-backed cache (default 5 min)
- Bilingual (EN / FR), auto-detects the client language
- Hardened against SSRF, XSS, path traversal

## Requirements / Prérequis

- WHMCS 8.x+
- PHP 7.4+
- SSH connectivity from the WHMCS server to the VPS hosts
- VPS managed by RunCloud (recommended) or any Linux host exposing `/proc/loadavg`, `free`, `df`, `/proc/uptime`

## Installation

1. Copy `modules/addons/hub_rc_vps/` into your WHMCS install:
   ```
   /your-whmcs/modules/addons/hub_rc_vps/
   ```

2. Install dependencies:
   ```bash
   cd /your-whmcs/modules/addons/hub_rc_vps/
   composer install --no-dev
   ```

3. Activate the module in WHMCS Admin:
   - Setup → Addon Modules → HUB RunCloud VPS Stats → **Activate**

4. Configure:
   - **SSH Private Key Path** – absolute path to the SSH private key on the WHMCS server
   - **SSH Username** – SSH user (recommended: `runcloud`)
   - **Cache TTL** – cache duration in seconds (default: 300)

## SSH Setup / Configuration SSH

### 1. Generate the SSH key on the WHMCS server

```bash
ssh-keygen -t ed25519 -f /path/to/hub_rc_vps_key -N "" -C "hub-rc-vps-whmcs"
```

> **Important (open_basedir)**: if WHMCS runs under PHP-FPM with `open_basedir`, the key must live inside an allowed directory. We recommend placing it directly inside the module folder as a hidden file:
> ```
> /your-whmcs/modules/addons/hub_rc_vps/.ssh_key
> ```
> Dotfiles are blocked by nginx-rc (403), so the key stays unreachable from the web.

### 2. Add the public key on each VPS via RunCloud

In RunCloud → Server → SSH → SSH Key → **Add New**:
- **Label**: `WHMCS`
- **User**: `runcloud` (recommended; avoids `PermitRootLogin` issues)
- Paste the public key (`.pub`)

### 3. Configure the WHMCS module

In WHMCS Admin → Setup → Addon Modules → HUB RunCloud VPS Stats:
- **SSH Private Key Path**: `/your-whmcs/modules/addons/hub_rc_vps/.ssh_key`
- **SSH Username**: `runcloud`

### Why `runcloud` and not `root`?

- Some RunCloud servers ship with `PermitRootLogin` disabled
- The `runcloud` user has access to everything we need (`/proc/loadavg`, `free`, `df`, `/proc/uptime`, FPM configs)
- Less risk of being blocked by SSH security policies

## WHMCS service configuration

For each VPS client service, fill in the **Dedicated IP** field with the VPS IP address. The stats widget will appear automatically on the product details page.

Custom SSH port supported via `IP:PORT` format (e.g. `1.2.3.4:2222`).

## Security

### IP restriction (recommended)

You can restrict the SSH key so it is only accepted from the WHMCS server IP. On each VPS, in `/home/runcloud/.ssh/authorized_keys`, prefix the key:

```
from="WHMCS_IP",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA... hub-rc-vps-whmcs
```

> **Note**: RunCloud may overwrite `authorized_keys` when SSH keys are edited via its UI. Re-check after any change.

### Built-in protections

- **SSRF**: private and reserved IPs rejected (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`)
- **XSS**: all output escaped with `htmlspecialchars()`
- **Path traversal**: language name sanitized (`preg_replace('/[^a-z0-9_-]/', '', $language)`)
- **Sensitive files**: `.ssh_key` and `.debug.log` are dotfiles, blocked by nginx-rc
- **SQL injection**: Capsule ORM (parameterized queries)

## Troubleshooting / Dépannage

### "SSH authentication failed"

1. Make sure the WHMCS public key is added to the `runcloud` user (not `root`) in RunCloud → SSH
2. Verify the **SSH Username** module setting is `runcloud`
3. If using `root`, check that `Prevent root login` is not enabled
4. Purge the module cache from the admin page

### "Connection refused"

1. Check port 22 is open in RunCloud → Security → Firewall (Global type)
2. Check that **fail2ban** has not banned the WHMCS IP (failed attempts can trigger bans)
3. To unban: `fail2ban-client unban WHMCS_IP` on the VPS

### "Password change required"

Some servers require a root password change on first connection. Either log in manually once or switch to the `runcloud` user.

## Architecture

```
modules/addons/hub_rc_vps/
├── hub_rc_vps.php        # Main module (config, admin, SSH test)
├── hooks.php             # ClientAreaProductDetailsOutput hook + HTML rendering
├── lib/
│   ├── ServerStats.php   # SSH client (phpseclib) + system stats parser
│   └── Cache.php         # DB cache (mod_hub_rc_vps_cache)
├── lang/
│   ├── english.php
│   └── french.php
├── .ssh_key              # Private SSH key (NOT committed, blocked by nginx)
├── .debug.log            # Debug log (NOT committed, blocked by nginx)
└── vendor/               # phpseclib (composer)
```

## RunCloud webapp detection

The module detects RunCloud webapps by scanning FPM sockets in `/run/`, mapping each socket to its `php-fpm: master` parent process to extract the PHP version. Format: `app-name|phpversion` (e.g. `app-mysite|82` → PHP 8.2).

This approach works for both `root` and `runcloud` SSH users (no privileged file access required).

## Contributing

Pull requests are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

For bug reports and feature requests, please use the [GitHub issue tracker](https://github.com/collectifweb/HUB_RC-VPS/issues).

## License

[MIT](LICENSE) © [Collectif HUB](https://collectif-hub.ca)
