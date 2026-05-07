# Webserver Ubuntu configuration — Locally (Apache2, PHP, MySQL, React build)

End-to-end steps to take a fresh **Ubuntu Server** machine from **updates** to a **running** Locally site: database imported, PHP API behind **Apache 2**, and the **built** React app served **on the same hostname** (`/api` and `/uploads` go to the backend; everything else loads the SPA so React Router works).

Adjust **passwords**, **domains**, and **Unix usernames** to match your server.

---

## What you get after this guide

| Component | Role |
|-----------|------|
| **MySQL/MariaDB** | Application database (`database/schema.sql` + optional migrations/seed). |
| **Apache 2 + PHP** | **`backend/public`** is the web root: **`/api/*`** → PHP `index.php`, static/`/uploads` as files, other paths → **`index.html`** (SPA). |
| **Node.js** | Used **once** on the server to run `npm ci` / `npm run build` for `frontend/` (no Node daemon in production). |

**Suggested deployment path on the server (example):**

```text
/var/www/locally/                    ← Git clone root (repository root)
├── README.md
├── WEBSERVER_UBUNTU_CONFIGURATION.md
├── frontend/                        ← React source; production build outputs to frontend/dist/
│   ├── .env.example
│   ├── dist/                        ← created by `npm run build` (not in Git—build on server)
│   ├── package.json
│   └── src/
├── backend/
│   ├── .env                         ← YOU create from .env.example (secrets; not in Git)
│   ├── .env.example
│   ├── bootstrap.php
│   ├── composer.json
│   ├── storage/
│   │   └── rate-limits/             ← writable by www-data at runtime
│   └── public/                      ← Apache DocumentRoot MUST point HERE
│       ├── index.php               ← PHP entry (API bootstrap)
│       ├── index.html              ← SPA entry (YOU copy after build; see steps)
│       ├── assets/                 ← YOU copy built JS/CSS from frontend/dist/assets/
│       ├── favicon.svg             ← Optional: copy from frontend/public/ if needed
│       ├── .htaccess               ← YOU configure for SPA + `/api` (see below)
│       └── uploads/                ← PRODUCT images dir; writable by www-data
└── database/
    ├── schema.sql
    ├── seed.sql
    └── migrations/
```

---

## Prerequisites

- **Ubuntu Server 22.04 LTS** or **24.04 LTS** (recommended).
- **SSH** access with `sudo`.
- DNS **A record** pointing to the server IP (for HTTPS/Let’s Encrypt).

---

## 1. SSH in and prepare

```bash
ssh your_user@YOUR_SERVER_IP
sudo -v
```

---

## 2. Fully update Ubuntu

Update package lists and install security upgrades (`-y` answers “yes” to prompts where safe).

```bash
sudo apt update
sudo apt upgrade -y
sudo apt full-upgrade -y
sudo reboot
```

After reboot, reconnect by SSH before continuing.

---

## 3. Install Apache 2

```bash
sudo apt install -y apache2
sudo systemctl enable --now apache2
sudo apt install -y openssl
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

---

## 4. Install MySQL Server

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
```

Hard MySQL passwords and remove test DB (recommended). Follow the prompts:

```bash
sudo mysql_secure_installation
```

Create database and dedicated user (**replace passwords**):

```bash
sudo mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS locally CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'locally'@'localhost' IDENTIFIED BY 'STRONG_RANDOM_PASSWORD_HERE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, ALTER, INDEX
  ON locally.* TO 'locally'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Import schema (paths assume clone at **`/var/www/locally`**; run after cloning in section 11):

```bash
mysql -u locally -p locally < /var/www/locally/database/schema.sql
```

Incremental migration (needed if you already imported an older snapshot):

```bash
mysql -u locally -p locally < /var/www/locally/database/migrations/20260505_orders_checkout_payment.sql
```

Optional demo seed (**not for production** — weak default passwords documented in repo `README.md`):

```bash
mysql -u locally -p locally < /var/www/locally/database/seed.sql
```

---

## 5. Install PHP (8.2 or newer) + Apache PHP module + extensions

This project requires **PHP 8.2+** (see `backend/composer.json`).

Ubuntu 22.04/24.04 typically ships PHP **8.x** compatible with Locally.

```bash
sudo apt install -y php libapache2-mod-php php-cli php-mysql php-mbstring \
  php-xml php-curl php-zip php-gd php-fileinfo php-json
php -v
```

Enable rewrite (needed for clean URLs):

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl restart apache2
```

---

## 6. Increase upload limits for admin product photos

Roughly aligns with README expectations (~2.5 MB uploads). Tune as needed:

```bash
PHP_INI="/etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/apache2/php.ini"
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 12M/' "$PHP_INI"
sudo sed -i 's/^post_max_size = .*/post_max_size = 16M/' "$PHP_INI"
sudo sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
sudo systemctl restart apache2
```

---

## 7. Install Node.js (to build the frontend only)

Use **Current LTS** (Node 22 as of typical 2026 stacks) or Node 20 LTS—anything that satisfies Vite/React in `frontend/package.json`. Example using **NodeSource** (Official Node.js binary packages):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

You do **not** run `npm run dev` on the production server permanently; only build once after updates.

---

## 8. Firewall (recommended)

Allow SSH **before** enabling UFW:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
sudo ufw status verbose
```

---

## 9. Put the project on the server via GitHub

On your laptop/desktop, push the repo (you already planned this):

```bash
git init      # only if repo not initialized
git add .
git commit -m "Initialize Locally monorepo"
git branch -M main
git remote add origin https://github.com/YOU/locally.git
git push -u origin main
```

On the Ubuntu server (**replace repo URL**):

```bash
sudo mkdir -p /var/www
sudo chown "$USER:$USER" /var/www
cd /var/www
git clone https://github.com/YOU/locally.git locally
cd locally
```

---

## 10. Optional: Composer autoload (optional clarity)

Composer is optional; `bootstrap.php` autoloads `Locally\*` classes. Running Composer only refreshes Composer’s optimized autoload if you added `vendor/` later:

```bash
cd /var/www/locally/backend
composer --version >/dev/null 2>&1 || sudo apt install -y composer
composer dump-autoload -o || true
```

---

## 11. Backend environment (`backend/.env`)

```bash
cd /var/www/locally/backend
cp .env.example .env
nano .env   # or vim
```

Minimal production-oriented values (**replace**:

| Variable | Typical production meaning |
|-----------|----------------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `0` |
| `APP_URL` | `https://YOUR_DOMAIN_COM` |
| **`CORS_ORIGIN`** | **Exact origin of the SPA in the browser**, e.g. `https://YOUR_DOMAIN_COM` (must match scheme + host + optional port.) |
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `locally` |
| `DB_USER` / `DB_PASSWORD` | MySQL credentials you created. |
| **`SESSION_SECURE`** | **`1`** when the site is **HTTPS-only**. |

Save and lock down permissions:

```bash
chmod 640 /var/www/locally/backend/.env
```

Ensure upload directory exists:

```bash
mkdir -p /var/www/locally/backend/public/uploads
mkdir -p /var/www/locally/backend/storage/rate-limits
```

---

## 12. Frontend build (Node) and publish into `backend/public`

Build with **production** API base (same-origin):

```bash
cd /var/www/locally/frontend
cp .env.example .env
printf 'VITE_API_BASE_URL=/api\n' > .env
npm ci
npm run build
```

Copy **built assets** plus **`index.html`** into Apache’s PHP web root (**merge**—do not delete `index.php`):

```bash
rsync -av --delete /var/www/locally/frontend/dist/assets/ \
  /var/www/locally/backend/public/assets/
install -m 0644 /var/www/locally/frontend/dist/index.html \
  /var/www/locally/backend/public/index.html
# Optional favicon (if built):
[ -f /var/www/locally/frontend/dist/favicon.svg ] && \
  cp /var/www/locally/frontend/dist/favicon.svg /var/www/locally/backend/public/favicon.svg
```

When someone requests **`/`**, Apache serves **`DirectoryIndex`** (see **`index.html` first**) in `.htaccess` below.

---

## 13. Production `.htaccess` in `backend/public`

The stock repo `.htaccess` sends **every** unmatched path to `index.php`. On production you must:

- **`/api/**`** → **`index.php`** (Kernel routes).
- **Existing files** (e.g. `/uploads/...`, `/assets/...`) → served as static files.
- **All other URLs** → **`index.html`** (React Router).

Replace **`/var/www/locally/backend/public/.htaccess`** with:

```apache
# Prefer the SPA shell for bare directory requests (/ → index.html; keep index.php for /api fallback).
DirectoryIndex index.html index.php

<IfModule mod_rewrite.c>
  RewriteEngine On

  # Serve existing files or directories unchanged
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]

  # API → PHP bootstrap
  RewriteRule ^api/ index.php [L]

  # React SPA fallback
  RewriteRule ^ index.html [L]
</IfModule>
```

If Apache ignores `.htaccess`, ensure `AllowOverride All` inside the `<Directory>` for `backend/public` in your vhost (see next section).

---

## 14. Apache virtual host (HTTP first; HTTPS follows)

Replace **`YOUR_DOMAIN_COM`** and paths if your clone differs.

```bash
sudo tee /etc/apache2/sites-available/locally.conf >/dev/null <<'APACHE'
<VirtualHost *:80>
    ServerName YOUR_DOMAIN_COM
    ServerAdmin webmaster@localhost

    DocumentRoot /var/www/locally/backend/public

    # Allow Laravel-style overrides for rewrite rules above
    <Directory /var/www/locally/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/locally_error.log
    CustomLog ${APACHE_LOG_DIR}/locally_access.log combined
</VirtualHost>
APACHE
```

Enable site & reload (**edit** `YOUR_DOMAIN_COM` in the file if you pasted literally):

```bash
sudo nano /etc/apache2/sites-available/locally.conf   # replace placeholder domain
sudo a2ensite locally.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

DNS must point **`YOUR_DOMAIN_COM`** → server IP **before** Let’s Encrypt.

---

## 15. TLS with Let’s Encrypt (recommended)

Install Certbot for Apache:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d YOUR_DOMAIN_COM
sudo systemctl reload apache2
```

Set in **`backend/.env`** after HTTPS:

- `APP_URL=https://YOUR_DOMAIN_COM`
- `CORS_ORIGIN=https://YOUR_DOMAIN_COM`
- `SESSION_SECURE=1`

Then reload PHP/Apache:

```bash
sudo systemctl restart apache2
```

---

## 16. File ownership & permissions for Apache (`www-data`)

```bash
sudo chown -R www-data:www-data /var/www/locally/backend/public/uploads \
  /var/www/locally/backend/storage
sudo find /var/www/locally/backend/public/uploads /var/www/locally/backend/storage -type d -exec chmod 775 {} \;
sudo find /var/www/locally/backend/public/uploads /var/www/locally/backend/storage -type f -exec chmod 664 {} \;
```

Keeping the rest of `locally/` owned by your deploy user is fine (`www-data` only needs write on uploads + storage). If uploads fail silently, widen ownership on `public/uploads` only.

---

## 17. Sanity checks from the shell

(Optional: **`sudo apt install -y jq`** for pretty JSON.)

```bash
curl -fsS https://YOUR_DOMAIN_COM/api/health | jq .
curl -fsSI https://YOUR_DOMAIN_COM | head -n 5
curl -fsS https://YOUR_DOMAIN_COM/ | head -n 5
```

- **`/api/health`** envelope should include **`data.database.connected: true`** (and related fields) once MySQL accepts `DB_*` from `.env`.
- **`/`** should return **`index.html`** (HTML `<!doctype html>` snippet).

Browser: open **`https://YOUR_DOMAIN_COM`** — SPA loads; **`https://YOUR_DOMAIN_COM/api/csrf`** should return `{ "ok": true, ... }`.

---

## 18. Updating the application after pushing to GitHub

```bash
cd /var/www/locally
git pull   # run as whatever Unix user owns the checkout (recommended), not necessarily www-data
# Apply new SQL migrations only when the main README introduces them (replace path):
# mysql -u locally -p locally < database/migrations/YOUR_NEW_FILE.sql

cd frontend
npm ci
npm run build
rsync -av --delete dist/assets/ ../backend/public/assets/
install -m 0644 dist/index.html ../backend/public/index.html
sudo systemctl reload apache2    # reload is enough for `.htaccess` / static swaps; restart after `.env`/PHP.ini changes
```

> **Important:** **`database/schema.sql`** contains **`DROP TABLE`** statements at the top. Use it **only on a fresh DB** or a disposable environment—not on production with live data unless you intentionally reset.

---

## 19. Common problems

| Symptom | What to verify |
|---------|----------------|
| **`403 Forbidden`** | `Require all granted`; `chmod`/`chown`; `AllowOverride All`. |
| **React routes 404 reload** | Production `.htaccess` must fallback to **`index.html`** (section 13). |
| **`/api`** returns SPA HTML | Ensure **`RewriteRule ^api/ index.php`** runs before SPA fallback—`.htaccess` order as written. |
| **CORS errors** | **`CORS_ORIGIN`** equals **exact browser origin**, including **`https`** and no trailing mismatch. |
| **Session not sticking** | `SESSION_SECURE=1` requires HTTPS; clocks skewed NTP fixes rare cookie expiry issues. |
| **DB unreachable** | `DB_HOST`,`DB_*` in `.env`; `mysql -u locally -p` from shell. |
| **`uploads` fail** | `public/uploads` ownership `www-data`, PHP **`upload_max_filesize`/`post_max_size`**. |

---

## 20. Security reminders (production)

1. Rotate **every** demo password (`seed.sql` users are insecure).
2. Mark `APP_DEBUG=0` outside development.
3. Prefer **least privilege DB user** (`GRANT … ON locally.*` only).
4. Keep Apache/PHP/OS patched (`sudo apt update && sudo apt upgrade`).
5. The demo **Visa** page stores card-like data client-side (**not PCI-safe**)—never enable real PAN/CVV workflows without a **tokenization PSP**.

---

That completes **Ubuntu refresh → Git clone → DB → `.env` → Node build → Apache vhost → HTTPS → running Locally**.
