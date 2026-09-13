# Deployment — Ask a Human MVP

Target: ordinary PHP shared hosting. No Composer, Node.js, Redis, workers, or
cron are required.

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`
  (recommended; a stream fallback exists for Telegram), `session`, `json`.
- MySQL 5.7+ / MariaDB with InnoDB and `utf8mb4`.
- Apache with `mod_rewrite` (primary target). The built-in PHP server also
  works for testing (see below).
- A TLS certificate (Let's Encrypt is fine) — production cookies are `Secure`.

## 1. Upload

### Variant A — document root is `public/` (VPS, Plesk)

Point the vhost `DocumentRoot` at the repository's `public/` directory. Keep
`app/`, `config/`, `database/`, `templates/` outside the document root.

### Variant B — shared host with `public_html`

Upload the repository anywhere outside the web root (for example
`~/askahuman`), and copy/symlink the contents of `public/` into
`~/public_html`. Then set:

```
APP_BASE_PATH=/home/USER/askahuman
```

`public/index.php` uses `APP_BASE_PATH` to find `app/bootstrap.php`; when it is
empty, the parent of `public/` is used.

## 2. Database

Create a database and a least-privilege user (control panel or CLI):

```sql
CREATE DATABASE ask_a_human CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ask_a_human'@'localhost' IDENTIFIED BY 'strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ask_a_human.* TO 'ask_a_human'@'localhost';
```

Initialize the schema and load the example data once (fresh installs):

```bash
mysql -u admin-user -p ask_a_human < database/migrations/001_initial.sql
mysql -u ask_a_human -p ask_a_human < database/seed.sql
```

> `001_initial.sql` is the single, complete schema; there are no other
> migrations. It runs as a MySQL admin user (it creates tables); the
> application user keeps only SELECT/INSERT/UPDATE/DELETE. `seed.sql` loads a
> clearly fictional example human plus one answered example question.
>
> A fresh install then needs the Telegram command menu registered once:
> `php scripts/set-bot-commands.php` (uses the production config).

The seed uses `INSERT ... ON DUPLICATE KEY UPDATE` on unique slugs, so
re-running it never duplicates rows. Re-running the migration fails
clearly on existing tables; it is intentionally not a framework.
Replace or delete the example profile and question before going live.

## 3. Configuration

Preferred: real environment variables (Apache `SetEnv` or
`SetEnvIf`/`.htaccess`, php-fpm pool `env[...]`). Fallback: copy
`config/local.php.example` to `config/local.php` (git-ignored) and fill it.

Generate secrets locally — never commit them:

```bash
php -r "echo password_hash('your-admin-password', PASSWORD_DEFAULT), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # RATE_LIMIT_SECRET
```

Production requires: `APP_URL` (canonical HTTPS), `DB_*`, `ADMIN_PASSWORD_HASH`,
`RATE_LIMIT_SECRET` (≥32 chars), `CONTACT_EMAIL`, and for the real experiment
`TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID`. See `.env.example` for the full list.
Missing required values produce a safe, explicit configuration error page.

## 4. Telegram

1. Create a bot with @BotFather, copy the token into `TELEGRAM_BOT_TOKEN`.
2. Send any message to the bot from the answering human's account, then find
   the chat id (e.g. via `https://api.telegram.org/bot<TOKEN>/getUpdates`) and
   set `TELEGRAM_CHAT_ID`.
3. Submit a test question and confirm one notification arrives with a working
   admin link.

Notification is synchronous best-effort with 2 s connect / 4 s overall
timeouts. Failures are logged and never lose a question.

## 5. Local testing without Apache

```bash
php -S 127.0.0.1:8080 public/index.php
```

Run the dependency-free logic tests:

```bash
php tests/run.php
```

Notification is synchronous best-effort with 2 s connect / 4 s
overall timeouts. Failures are logged and never lose a question.

### Answering from Telegram (webhook)

Operators can answer without opening the site: replying to a notification
message publishes the reply verbatim as the public answer; replying
`/decline <reason>` declines. Delivery requires a one-time webhook
registration per bot:

1. The webhook endpoint is `POST {APP_URL}/telegram/webhook`, authenticated
   by a secret derived from `RATE_LIMIT_SECRET`:
   `hash_hmac('sha256', 'telegram-webhook', RATE_LIMIT_SECRET)`.
2. If the hosting cannot accept connections from Telegram (some providers
   filter Telegram ranges), terminate the webhook on a reachable relay
   (nginx `proxy_pass` + self-signed certificate) and pass that certificate
   to `setWebhook` via the `certificate` field.
3. If `api.telegram.org` is unreachable from the app host, set
   `TELEGRAM_API_BASE` to an authenticated relay
   (`X-Relay-Key: hash_hmac('sha256', 'telegram-relay', RATE_LIMIT_SECRET)`;
   self-signed relays are trusted only in this override mode).

The progress fields surfaced to agents (`notified_at`, `viewed_at`,
`answered_via`) are honest signals only: Telegram does not expose bot read
receipts, so "viewed" means the human opened the question in the admin area.

## 6. Launch checklist (operational)

1. Enforce HTTPS and redirect the non-canonical host to the canonical
   `APP_URL` (Apache `Redirect`/`RewriteRule` or host panel).
2. Verify the site in Google Search Console and Bing Webmaster Tools.
3. Submit `/sitemap.xml` in both.
4. Request indexing for `/`, `/for-agents`, the human profiles, and the
   first answers.
5. Publish 5–10 truthful seed Q&A pages, clearly genuine starter questions.
6. Check rendered HTML, canonical tags, noindex transitions after answering,
   Rich Results Test, and Schema Markup Validator.
7. Inspect logs/Search Console over time; do not attribute discovery to
   `llms.txt` without evidence.

## Known launch-blocking inputs

- Real canonical `APP_URL` (currently example values in sample config).
- Real `CONTACT_EMAIL` for removal requests.
- Real Telegram credentials.
- Generated `ADMIN_PASSWORD_HASH` and `RATE_LIMIT_SECRET`.
