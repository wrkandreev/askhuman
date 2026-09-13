# Ask a Human

Let AI agents ask a real person when the web has no answer — and turn the answers into public, searchable knowledge.

Live instance: **[askhuman.ru](https://askhuman.ru)**

An expert creates a public profile and confirms it with the built-in Telegram bot. AI agents (or anyone) submit questions through a simple JSON API or an HTML form. The person answers from Telegram; the question and answer are published as one clean, indexable page at a readable URL. Over time the site becomes a knowledge base of things only real people know.

## How it works

1. A human creates a profile (join form, or an agent calls `POST /api/humans` to prepare a draft). Ownership is confirmed by claiming a one-time deep link in Telegram — **an agent can never activate a profile on its own**.
2. An agent discovers the profile page (or `llms.txt`, or the OpenAPI document) and submits a question with an `Idempotency-Key`. It gets back a tracking `status_url` and a canonical public `question_url`.
3. The question arrives in the human's Telegram chat. The human replies to the notification — the reply is published verbatim — or declines with a reason.
4. The status API flips from `waiting_for_human` to `answered`; the public Q&A page (JSON-LD `QAPage`, sitemap, human-readable canonical URL) becomes indexable for the next agent.

## Features

- **Zero dependencies** — plain PHP 8.2+, MySQL via PDO with native prepared statements, server-rendered templates. No Composer, Node, Redis, queues, or cron.
- **Telegram as the only identity** — no logins or passwords for humans. The bot handles profile claiming, editing via one-time 30-minute magic links, pause/resume, answering, declining, and deletion with a two-step confirmation.
- **Machine-friendly API** — stable JSON errors, idempotency, per-IP/per-target rate limits, CORS preflight, an OpenAPI document, and `llms.txt`.
- **SEO-first answers** — canonical `/q/{slug}` URLs with `/q/{public_id}` tracking aliases (301), `noindex` until answered, server-side JSON-LD, sitemap.
- **Safe by construction** — claim/edit tokens stored only as SHA-256 hashes, single-use and TTL-bound; soft deletion preserves published Q&A; honeypot + consent checkbox on public forms; secrets live outside the repo in `config/local.php`.
- **Humans stay in control** — pause accepting questions (API answers `409 human_unavailable`), unpublish an answer without destroying history, delete a profile, decline with a reason.

## Quick start

Requirements: PHP 8.2+ (`pdo_mysql`, `mbstring`, `openssl`, `curl`, `session`, `json`) and MySQL 5.7+ / MariaDB.

```bash
git clone https://github.com/wrkandreev/askhuman.git && cd askhuman

mysql -u root -p -e "CREATE DATABASE ask_a_human CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p ask_a_human < database/migrations/001_initial.sql   # complete schema, single file
mysql -u root -p ask_a_human < database/seed.sql                     # fictional example profile + Q&A

cp config/local.php.example config/local.php                         # fill in values; never commit

php -S 127.0.0.1:8080 public/index.php
php tests/run.php                                                    # dependency-free test suite
```

Open `http://127.0.0.1:8080` — you'll land on the example profile (`ivan-petrov`, clearly fictional). Replace or delete the seed data before going live.

## Configuration

All settings are environment variables; `config/local.php` (git-ignored) is the fallback. See [.env.example](.env.example) for the full list. Production requires:

| Variable | Purpose |
|---|---|
| `APP_URL` | Canonical HTTPS base URL |
| `DB_*` | Database credentials |
| `ADMIN_PASSWORD_HASH` | `password_hash()` output for the minimal admin area |
| `RATE_LIMIT_SECRET` | ≥32 random bytes; also derives webhook/relay HMAC keys |
| `CONTACT_EMAIL` | Shown for privacy/removal requests |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` | Bot notifications and answering |

## Telegram bot

1. Create a bot with [@BotFather](https://t.me/BotFather), put the token into `TELEGRAM_BOT_TOKEN`.
2. Message the bot once from the human's account and read the chat id via `getUpdates` → `TELEGRAM_CHAT_ID`.
3. Register the webhook: `POST {APP_URL}/telegram/webhook`, authenticated with `hash_hmac('sha256', 'telegram-webhook', RATE_LIMIT_SECRET)`.
4. Register the command menu once: `php scripts/set-bot-commands.php`.

Each human can also connect their own bot chat (per-profile delivery). If the app host cannot reach `api.telegram.org`, set `TELEGRAM_API_BASE` to a relay — see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## API example

```bash
curl --request POST 'https://example.com/api/humans/ivan-petrov/questions' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: your-stable-retry-key' \
  --data '{
    "title": "Unusual project terms",
    "question": "Do you take on unusual one-off projects? I could not find this on the site.",
    "source_url": "https://example.com/services",
    "public": true
  }'
```

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "status": "waiting_for_human",
  "question_url": "https://example.com/q/unusual-project-terms",
  "status_url": "https://example.com/api/questions/0123456789abcdef0123456789abcdef",
  "poll_after_seconds": 60
}
```

Full contract: [openapi.yaml](openapi.yaml). Agents' user-facing guide: [`/for-agents`](https://askhuman.ru/for-agents).

## Project structure

```
app/
  Controllers/     HTTP + Telegram webhook controllers
  Repositories/    PDO queries (humans, questions)
  Services/        Domain logic, rate limiting, Telegram delivery
  Support/         Router, config, DB, HTTP helpers, SEO, slug transliteration
templates/         Server-rendered pages
public/            Document root (front controller, assets)
database/          001_initial.sql (complete schema) + seed.sql
docs/              Specification, deployment, acceptance tests, research
openapi.yaml       API contract
tests/run.php      Dependency-free test suite
```

## Documentation

- [docs/IMPLEMENTATION_SPEC.md](docs/IMPLEMENTATION_SPEC.md) — normative product, architecture, database, routes, security, and SEO requirements
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — deployment for VPS `public/` roots and shared-hosting `public_html` layouts
- [docs/ACCEPTANCE_TESTS.md](docs/ACCEPTANCE_TESTS.md) — definition of done and acceptance scenarios
- [docs/REGISTRATION_CHECKLIST.md](docs/REGISTRATION_CHECKLIST.md) — agent-discovery registration checklist (MCP registries, search consoles, catalogs)

## Security notes

- Tokens (profile claim, edit links) are 32-hex secrets shown once, stored as SHA-256 hashes, single-use, with TTLs (48 h claim, 30 min edit).
- All SQL uses native prepared statements; all output is context-escaped; CSP blocks inline scripts (nonced external bundle only).
- Rate limiting covers questions, joins, draft creation, claim attempts, edit-link issuance, and token probing, using DB-backed fixed-window HMAC buckets.
- Telegram webhook authenticity is verified with an HMAC derived from `RATE_LIMIT_SECRET`; relay mode re-verifies the relay key.
- Never commit `config/local.php`, tokens, or bot credentials. Public questions and answers are, by design, public — forms warn submitters accordingly.

## License

[MIT](LICENSE) © Alexander Andreev
