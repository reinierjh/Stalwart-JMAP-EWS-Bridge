# Installatie Stalwart JMAP-EWS Bridge op Debian Trixie

## 1. Afhankelijkheden installeren

```bash
apt update
apt install -y nginx php8.4-fpm php8.4-xml php8.4-mbstring git unzip certbot python3-certbot-nginx
```

`php8.4-xml` is nodig voor `DOMDocument` en `SimpleXML` (ingebouwd in PHP, maar `php-xml` installeert de libxml bindingen zeker).

## 2. Code plaatsen

```bash
mkdir -p /var/www/ews-bridge
cd /var/www/ews-bridge
git clone <jouw-repo-url> .
```

Of via rsync/scp:

```bash
rsync -av --delete ./ jmap-ews-bridge/ root@server:/var/www/ews-bridge/
```

## 3. Configuratie aanpassen

```bash
cp jmap/config.example.php jmap/config.php
nano jmap/config.php
```

Pas `JMAP_SESSION_URL` aan naar je Stalwart server (bv. `https://mail.example.com/.well-known/jmap`).

Optioneel: pas de JMAP-credentials en limieten aan in `jmap/config.php` en `ews/config/config.php`.

## 4. Nginx configuratie

```bash
cp ews/config/nginx-ews-bridge.conf /etc/nginx/sites-available/ews-bridge
nano /etc/nginx/sites-available/ews-bridge
# Pas server_name aan naar jouw domein
ln -s /etc/nginx/sites-available/ews-bridge /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default  # remove default if needed
nginx -t
systemctl reload nginx
```

## 5. HTTPS (Let's Encrypt)

```bash
certbot --nginx -d ews.voorbeeld.nl
```

Certbot past de nginx config automatisch aan.

## 6. PHP-FPM optimalisaties (optioneel)

```bash
# /etc/php/8.4/fpm/pool.d/www.conf
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 5
request_terminate_timeout = 120s

systemctl restart php8.4-fpm
```

## 7. Permissies

```bash
chown -R www-data:www-data /var/www/ews-bridge
chmod -R 755 /var/www/ews-bridge
```

De code bevat geen schrijfbare directories; alles is read-only.

## 8. Testen

Via PHP's built-in server:

```bash
php -S 0.0.0.0:8000 -t . ews/run.php
```

Of direct via nginx (als HTTPS geconfigureerd is):

```bash
curl -k -u "gebruiker@domein:wachtwoord" \
  -H "Content-Type: application/xml" \
  -X POST "https://ews.voorbeeld.nl/EWS/Exchange.asmx" \
  -d '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><SyncFolderHierarchy xmlns="http://schemas.microsoft.com/exchange/services/2006/messages"><FolderShape><t:BaseShape>AllProperties</t:BaseShape></FolderShape></SyncFolderHierarchy></soap:Body></soap:Envelope>'
```

## 9. macOS Mail.app configureren

- Account type: **Exchange**
- Server: `https://ews.voorbeeld.nl/EWS/Exchange.asmx`
- Gebruikersnaam: `<stalwart-username>`
- Wachtwoord: `<stalwart-password>`
- **Werkt op macOS 10.8 t/m 12.x (Monterey)**

## Bestandsstructuur

```
/var/www/ews-bridge/
├── ews/
│   ├── bootstrap.php          # Autoloader + requires
│   ├── compat.php             # Z-Push stubs
│   ├── config/
│   │   ├── config.php         # EWS configuratie
│   │   └── nginx-ews-bridge.conf  # Nginx voorbeeld
│   ├── public/
│   │   ├── index.php          # Front controller
│   │   └── .htaccess          # Alleen voor Apache
│   ├── run.php                # PHP built-in server runner
│   └── src/
│       ├── Server.php
│       ├── Soap.php
│       ├── Converter.php
│       └── Operations.php
├── jmap/
│   ├── config.php
│   ├── config.example.php
│   ├── jmap_client.php
│   ├── jmap_contacts.php
│   └── jmap_calendar.php
└── docs/
    └── INSTALL.md             # Dit bestand
```
