# Ask a Human — MVP implementation specification

Status: approved for implementation  
Specification date: 2026-09-12  
Target runtime: PHP 8.2 + MySQL on ordinary shared hosting

This document is normative. A future implementation session should be able to
build the application from it without access to the original conversation. If
an implementation shortcut conflicts with this document, preserve the product
behaviour and acceptance criteria first.

## 0. Concept pivot — 2026-09-13 (supersedes conflicting parts)

The primary user is now the human expert/owner connecting a "human endpoint"
to their website (root-level URL `/{slug}`). Agents discover the endpoint on
the expert's site (link, llms.txt, HTML), ask via
`POST /api/humans/{slug}/questions` (optional `source_url`), receive a
tracking URL, and retrieve the asynchronous human answer; answered Q&A pages
are indexed. `human_resources` is a separate entity (sites a human stands
behind); per-human Telegram delivery columns exist on `humans`. The home
page is a human-oriented product landing; agent docs stay on /for-agents.
Sections below describe the original MVP and remain valid where not
conflicting with this pivot.

## 1. Product goal

Build a small public service through which an AI agent can ask a real person a
question when it cannot find reliable information on the web.

The MVP tests one hypothesis:

> Can AI agents independently discover through web search that a human can be
> asked here, submit a question, retain a tracking URL, and later retrieve a
> human answer?

The intended loop is:

1. An agent searches the web and cannot find sufficient information.
2. The agent discovers this website through search.
3. It submits a question through HTML or JSON API.
4. The application stores the question and notifies the human in Telegram.
5. The application immediately returns a hard-to-guess tracking ID, public
   question URL, and API status URL.
6. The human answers from Telegram or a minimal password-protected admin page.
7. The API exposes the answer and the public page becomes indexable.
8. Search engines index the new public knowledge, so a future agent may find it
   without asking again.

The application does not claim that the submitter is genuinely an AI. It only
records basic request metadata needed to evaluate the experiment.

## 2. Scope

### 2.1 Included

- Public landing page with an HTML question form.
- Public, indexable human profiles.
- Public `/for-agents` instructions with examples.
- JSON API to submit and poll questions.
- Persistent MySQL storage.
- Best-effort Telegram notification for every accepted question.
- Public tracking/Q&A page for each question.
- Password-only administration area.
- Answer, edit answer, decline, and hide actions.
- Minimal experiment counters in the admin area.
- Semantic SSR HTML, canonical URLs, metadata, JSON-LD, sitemap, robots, OpenAPI,
  and `llms.txt`.
- Basic abuse protection suitable for an MVP.
- Deployment documentation for Apache/shared hosting.

### 2.2 Explicitly excluded

- Private questions.
- User accounts or submitter authentication.
- Multiple admin roles, ACL, or two-factor authentication.
- Payments.
- Queues, workers, cron requirements, Redis, WebSockets, or microservices.
- A frontend framework or JavaScript requirement for primary content.
- CAPTCHA.
- An LLM, vector database, automatic answer generation, or AI detection.
- Telegram conversations, commands, or answering inside Telegram.
- Webhooks/callback URLs for agents.
- Email notifications.
- Automatic translation.
- Markdown/HTML answers.
- RSS, Atom, IndexNow, analytics products, or tag managers.
- A general-purpose CMS for humans, profile content, or static pages.

## 3. Approved product decisions

### 3.1 Identity and positioning

- Working product name: `Ask a Human`, read from `APP_NAME`.
- Do not use `askhuman.ru` as the public brand unless configured later.
- Primary public language: Russian (`<html lang="ru">`).
- A human's native-spelling name may appear next to the Latin name on their
  profile page; do not build i18n routing.
- Profile URLs are `/{slug}` (the bundled example human lives at
  `/ivan-petrov`).
- Profile content belongs to each human: publish only facts that person
  confirmed. Do not invent a photo, job history, client list, awards,
  education, projects, social profiles, or conference links.
- Do not promise a response SLA. Say that Telegram notification is immediate
  and the human tries to respond as soon as possible.

### 3.2 Public-by-default model

- The MVP supports only `visibility = public`.
- The HTML form requires an unchecked consent checkbox.
- The API requires the literal JSON field `"public": true`.
- Missing or false API consent is a `422` validation error.
- Before submission, display this exact notice prominently:

  > This question and the human's answer will be published on the web and may
  > be indexed by search engines and AI systems. Do not submit passwords, API
  > keys, personal data, confidential information, or other secrets.

- Before an answer, the tracking page displays the submitted question and
  context, but uses a hard-to-guess URL and `noindex,follow`.
- Publishing an answer makes the reviewed public versions of the question and
  context indexable.
- An admin may edit the public question/context while retaining the immutable
  original submission in the database.

### 3.3 Question statuses

Persist these string values rather than a database enum:

- `waiting_for_human`: accepted and awaiting action.
- `answered`: a human answer is publicly available.
- `declined`: the human declined to answer; terminal and non-indexable.
- `hidden`: removed from all public/API access because of abuse, secrets,
  illegality, or another safety reason.

Rules:

- New questions start as `waiting_for_human`.
- Only `answered` pages enter the sitemap and become `index,follow`.
- `declined` remains `noindex,follow` and exposes a short decline reason.
- `hidden` behaves as `404 Not Found` on both HTML and API; never reveal that a
  hidden ID exists.
- Editing an answered question or answer keeps it `answered` and updates the
  sitemap `lastmod` value.

## 4. Technical constraints and architecture

### 4.1 Runtime compatibility

- PHP 8.2.
- PDO MySQL with native prepared statements.
- UTF-8 everywhere; database/tables use `utf8mb4`.
- MySQL 5.7+ compatibility is desirable; do not rely on database JSON operators,
  generated columns, database enums, or vendor-specific full-text behaviour.
- Apache rewrite rules are the primary hosting target.
- Primary document root is the repository's `public/` directory.
- The core application must run without Composer packages.
- JavaScript may provide minor progressive enhancement but no core flow may
  depend on it.

### 4.2 Proposed repository layout

```text
app/
  Controllers/
    AdminController.php
    ApiQuestionController.php
    PageController.php
    QuestionController.php
  Repositories/
    HumanRepository.php
    QuestionRepository.php
  Services/
    QuestionService.php
    RateLimiter.php
    TelegramNotifier.php
  Support/
    Config.php
    Database.php
    Http.php
    Router.php
    Seo.php
  bootstrap.php
config/
  local.php.example
database/
  migrations/001_initial.sql
  seed.sql
docs/
public/
  assets/app.css
  .htaccess
  index.php
templates/
  admin/
  errors/
  layouts/
  home.php
  for-agents.php
  human.php
  privacy.php
  question.php
  questions.php
tests/
  run.php
.env.example
.gitignore
openapi.yaml
README.md
```

Names may change modestly, but preserve the separation between HTTP/controller
logic, persistence, services, templates, and the public document root.

### 4.3 Request lifecycle

1. `public/.htaccess` sends non-file requests to `public/index.php`.
2. `index.php` loads `app/bootstrap.php` using `APP_BASE_PATH` when set, otherwise
   `dirname(__DIR__)`.
3. Bootstrap loads configuration, sets timezone to UTC, disables display of
   production errors, creates PDO, starts the secure session only on admin
   routes, and registers routes.
4. Router matches method and decoded path parameters exactly.
5. Controller validates input and calls a service/repository.
6. HTML controllers render escaped PHP templates; API controllers emit JSON.
7. A top-level exception handler logs an internal correlation ID and returns a
   generic `500` without stack traces, SQL, filesystem paths, or secrets.

Keep abstractions small. No dependency-injection container, ORM, active record,
facades, middleware framework, or event bus is required.

## 5. Configuration

Configuration priority:

1. real environment variable;
2. value returned by `config/local.php`;
3. documented non-secret default.

`config/local.php` must be ignored by Git and live outside the public document
root. `.env.example` is only a variable reference; do not implement or require a
`.env` parser. Hosts without environment-variable support can use the PHP config
file.

Required/recognized variables:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME="Ask a Human"
APP_URL=https://askhuman.ru
APP_BASE_PATH=
DISPLAY_TIMEZONE=Europe/Moscow

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ask_a_human
DB_USER=ask_a_human
DB_PASSWORD=replace-me

ADMIN_PASSWORD_HASH=replace-with-password_hash-output
RATE_LIMIT_SECRET=replace-with-at-least-32-random-bytes
CONTACT_EMAIL=owner@example.com

TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TRUSTED_PROXIES=
```

Rules:

- `APP_URL` is the sole source for absolute/canonical URLs. Never use the request
  `Host` header to generate public URLs.
- `APP_URL`, DB values, admin hash, rate-limit secret, and contact email are
  required in production.
- Empty Telegram values disable notification and log a configuration warning;
  they must be present for the real experiment.
- `APP_DEBUG=true` is forbidden in production.
- `TRUSTED_PROXIES` is a comma-separated list of exact proxy IP addresses. Only
  accept the first valid `X-Forwarded-For` address when `REMOTE_ADDR` is in this
  allowlist. Otherwise use `REMOTE_ADDR` exclusively.
- Store and return API timestamps in UTC ISO 8601 form such as
  `2026-09-12T15:20:30Z`.
- Public HTML may display dates in `DISPLAY_TIMEZONE`, while including machine
  readable ISO timestamps in `<time datetime="...">`.

## 6. Database

### 6.1 General rules

- Use InnoDB and `utf8mb4_unicode_ci` (or a widely supported equivalent).
- Application timestamps are generated in UTC.
- Use foreign keys for human/question/answer relationships.
- Never expose numeric database IDs publicly.
- Use parameterized queries for every value.
- Do not silently truncate user content. Validate lengths before insertion.
- JSON-shaped profile fields use `TEXT` for broad hosting compatibility and are
  encoded/decoded by PHP.

### 6.2 `humans`

```sql
CREATE TABLE humans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(190) NOT NULL,
    name_native VARCHAR(190) NULL,
    location VARCHAR(190) NULL,
    headline VARCHAR(255) NULL,
    bio TEXT NULL,
    expertise_json TEXT NULL,
    links_json TEXT NULL,
    projects_json TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_humans_slug (slug),
    KEY idx_humans_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`projects_json` deliberately starts as `[]`; it is a place for later verified
facts, not permission to invent projects.

Seed one clearly fictional example human (see `database/seed.sql`):

- slug: `ivan-petrov`
- name: `Ivan Petrov`
- native name: `Иван Петров`
- location: `Moscow, Russia`
- headline: `Software engineer and consultant`
- link label `Example Studio`, URL `https://example.com`
- one answered example question (`example-question`)
- replace or delete the example data before going live

### 6.3 `questions`

```sql
CREATE TABLE questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    human_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(190) NULL,
    question TEXT NOT NULL,
    context TEXT NULL,
    public_question TEXT NULL,
    public_context TEXT NULL,
    status VARCHAR(32) NOT NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'public',
    source VARCHAR(16) NOT NULL,
    user_agent VARCHAR(512) NULL,
    referrer VARCHAR(2048) NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    declined_reason TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_questions_public_id (public_id),
    UNIQUE KEY uq_questions_idempotency (idempotency_key_hash),
    KEY idx_questions_status_created (status, created_at),
    KEY idx_questions_human_created (human_id, created_at),
    KEY idx_questions_ip_created (ip_hash, created_at),
    CONSTRAINT fk_questions_human
        FOREIGN KEY (human_id) REFERENCES humans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Field rules:

- `public_id = bin2hex(random_bytes(16))`, giving 128 random bits.
- `slug` is cosmetic; identity and lookup use only `public_id`.
- `title` is an optional short heading (3–150 characters) supplied by the
  asker. When present it is used as the answer page `h1` and SEO title, and it
  seeds the slug; the stored value never changes at answer publication.
- Slug is derived from the first useful words of the title when present,
  otherwise of the question, lowercased and
  trimmed to at most 100 Unicode characters. It may contain Unicode. Replace
  punctuation/whitespace runs with `-`. If the result is empty, use `question`.
- The stored slug is immutable after creation, even when an admin reviews or
  edits the public question. This keeps every returned URL stable. Slugs are
  unique across questions (enforced by a unique key) and serve as the
  canonical URL segment `/q/{slug}`.
- `source` is exactly `html` or `api`.
- `visibility` is `public` in the MVP.
- Before publication, `public_question` and `public_context` are `NULL` and the
  tracking page/API use the original values.
- At answer publication, the admin form writes reviewed values into the public
  fields. `public_question` must not be empty. `public_context` may be empty.
- `ip_hash` is an HMAC-SHA-256 of the resolved IP address using
  `RATE_LIMIT_SECRET`; never store raw IP in application tables.
- `user_agent` and `referrer` are trimmed to the listed maximum byte-safe lengths.
  Invalid UTF-8 must be rejected or safely cleaned before storage.
- An idempotency hash is `HMAC-SHA256(ip_hash + NUL + Idempotency-Key,
  RATE_LIMIT_SECRET)`. Fingerprint is SHA-256 of canonical validated input
  (`question`, `title`, `context`, `public`, selected human).

### 6.4 `answers`

```sql
CREATE TABLE answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    human_id BIGINT UNSIGNED NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answers_question (question_id),
    KEY idx_answers_human_created (human_id, created_at),
    CONSTRAINT fk_answers_question
        FOREIGN KEY (question_id) REFERENCES questions(id),
    CONSTRAINT fk_answers_human
        FOREIGN KEY (human_id) REFERENCES humans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The first `created_at` is the answer time used for response-time metrics.
Subsequent edits change only `updated_at`.

### 6.5 `rate_limits`

Use a tiny database-backed fixed-window limiter so it works across PHP processes
without Redis:

```sql
CREATE TABLE rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The bucket key is an HMAC; it must not contain a raw IP. Implement atomic
`INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1`. Occasional lazy
cleanup of expired rows is allowed (for example, on roughly 1% of writes), but
the application must not require cron.

### 6.6 Optional `schema_migrations`

A one-column migration table is acceptable, but do not build a migration
framework. A documented `database/migrations/001_initial.sql` that can be run
once is sufficient for this MVP.

### 6.5 Expert lifecycle states

Humans.status values: `pending` (HTML application, waiting for pairing),
`awaiting_claim` (agent-created draft), `approved` (live),
`declined`/`hidden` (moderation), `deleted` (soft delete).

- `awaiting_claim`: created only by `POST /api/humans`. Never listed,
  never indexed, `/{slug}` returns 404, questions are rejected as with an
  unknown slug. Claim token is issued once (`claim_url` = Telegram deep
  link), stored as SHA-256 only, valid 48 h, single-use; claiming binds the
  Telegram chat and activates the profile. Drafts unclaimed for 7 days are
  purged lazily on the next draft creation.
- Pause: `humans.accepting_questions = 0` (`/pause` in Telegram). The
  profile stays public but shows a notice and hides the ask form; question
  creation (web and API) returns `409` with `error.code:
  "human_unavailable"`. `/resume` restores it.
- Self-service edit: `/edit` in Telegram issues a single-use magic link
  valid 30 minutes; only a SHA-256 of the token is stored, and saving the
  form invalidates it. No accounts, no passwords.
- Delete: `/delete` then `/confirm_delete` within 30 minutes (two-step).
  Soft delete (`status = "deleted"`): the profile page returns 404, the
  human disappears from catalogs/sitemap/llms.txt, but published questions
  and answers stay online as part of the knowledge base; answer pages show
  the human's name as plain text (no link).
- Answer unpublish: replying `/unpublish` to a question notification hides
  the answer (`answers.visible = 0`) and returns the question to
  `waiting_for_human`; the row is never destroyed and can be re-answered.
- Rate limits: draft creation 3/hour and 10/day per IP; claim attempts
  10/10 min per chat; edit-link issuance 5/hour per chat; invalid edit-token
  probes 10/hour per IP.

## 7. HTTP routes and behaviour

### 7.1 Public HTML

| Method | Path | Behaviour |
|---|---|---|
| GET | `/` | Landing page, form, human summary, 10 latest answers |
| POST | `/questions` | Validate HTML submission and create question |
| GET | `/questions` | Paginated answered-question archive |
| GET | `/q/{slug}` | Canonical Q&A page (pretty URL for humans and search) |
| GET | `/q/{public_id}` | Tracking URL; `301` to `/q/{slug}` |
| GET | `/q/{public_id}/{slug}` | Legacy URL; `301` to `/q/{slug}` |
| GET | `/humans/ivan-petrov` | Human profile (legacy `301` to `/{slug}`) |
| GET | `/for-agents` | Agent-oriented service/API instructions |
| GET | `/privacy` | Short privacy/publication notice |
| GET | `/openapi.yaml` | Serve the repository's static OpenAPI document |
| GET | `/llms.txt` | Plain-text LLM-oriented index |
| GET | `/sitemap.xml` | Dynamic XML sitemap |
| GET | `/robots.txt` | Dynamic plain-text crawler rules with sitemap URL |

HTML creation behaviour:

- Required fields: `question`, `public_consent`, and a valid signed form token.
- Optional: `context`.
- The form token is stateless and contains an issued timestamp plus a random
  nonce, authenticated with HMAC-SHA-256 using `RATE_LIMIT_SECRET`. Accept it for
  at most two hours. This lets the public form use an age check without setting
  a public tracking/session cookie. It is only an abuse signal: the unauthenticated
  public endpoint has no user authority that a conventional CSRF token protects.
- Include an off-screen honeypot field. If populated, return `422` with a generic
  form-level “The question could not be accepted” error and create no record; do
  not identify the honeypot or teach bots how detection worked.
- Read the issued timestamp from the verified form token. Return the same generic
  `422` form-level error for submissions under two seconds. API clients are not
  subject to form-time checks.
- On success, return `303 See Other` to the canonical question URL.
- On validation failure, return `422` and re-render escaped submitted values with
  field errors and the consent checkbox unchecked.

Tracking URL canonicalization:

- The canonical question URL is `/q/{slug}`; the `rel=canonical`, sitemap and
  `question_url` API values all use it.
- `/q/{valid_id}` and `/q/{valid_id}/{any_slug}` are stable tracking aliases;
  both return `301` to the canonical URL.
- Slugs are unique across questions: generated slugs get a `-2`, `-3`, …
  suffix when taken, and the table enforces a unique key.
- A slug that would look like a bare 32-hex tracking ID gets a `-q` suffix so
  the tracking route never shadows a canonical slug.
- Malformed or unknown IDs return `404` without database detail.
- Hidden IDs return the same `404`.

Archive behaviour:

- Include only `answered` questions.
- Order newest answered first.
- Page size: 20.
- Use `?page=N`, canonicalize page 1 to `/questions`, and provide ordinary HTML
  previous/next links.
- Do not create tag/category/filter pages in the MVP.

### 7.2 JSON API

| Method | Path | Success |
|---|---|---|
| POST | `/api/humans` | `201 Created`, draft profile (`awaiting_claim`) with a Telegram claim link |
| POST | `/api/questions` | `201 Created` and resource JSON |
| GET | `/api/questions/{public_id}` | `200 OK` and status JSON |
| OPTIONS | API paths | `204 No Content` with CORS headers |

`POST /api/questions` requirements:

- `Content-Type: application/json`; allow a charset parameter.
- Maximum raw request body: 32 KiB.
- Valid UTF-8 JSON object only.
- Required: `question` string and `public` literal boolean `true`.
- Optional: `context` string or null, `source_url` string or null, `title`
  string or null (short heading, 3–150 characters, see validation limits).
- Unknown fields are ignored for forward compatibility, except do not accept a
  client-supplied status, ID, answer, visibility other than the required public
  consent, or human ID.
- Optional `Idempotency-Key` header: 1–128 printable ASCII characters.

Validation limits count Unicode characters with `mb_strlen`:

- trimmed question: 20–4,000 characters;
- trimmed title: 0 (absent) or 3–150 characters;
- trimmed context: 0–8,000 characters;
- answer: 1–20,000 characters;
- decline reason: 1–1,000 characters.

First creation response:

```http
HTTP/1.1 201 Created
Content-Type: application/json; charset=utf-8
Location: https://example.com/api/questions/0123456789abcdef0123456789abcdef
Cache-Control: no-store
```

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "status": "waiting_for_human",
  "question_url": "https://example.com/q/example-question",
  "status_url": "https://example.com/api/questions/0123456789abcdef0123456789abcdef",
  "message": "Your question has been sent directly to a human. Save the status_url and check it later for the answer."
}
```

Idempotency behaviour:

- A repeated key with the same fingerprint returns the original representation,
  `200 OK`, the same `Location`, and `Idempotency-Replayed: true`.
- The same key with different validated content returns `409 Conflict`.
- Do not send another Telegram notification for a replay.

Waiting response:

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "status": "waiting_for_human",
  "title": "Unusual project terms",
  "question": "What is ...?",
  "context": "Optional context",
  "created_at": "2026-09-12T15:20:30Z",
  "answer": null,
  "question_url": "https://example.com/q/example-question",
  "poll_after_seconds": 60
}
```

Answered response:

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "status": "answered",
  "title": "Unusual project terms",
  "question": "Reviewed public question",
  "context": "Reviewed public context",
  "answer": "The human answer.",
  "answered_by": {
    "name": "Ivan Petrov",
    "url": "https://example.com/humans/ivan-petrov"
  },
  "created_at": "2026-09-12T15:20:30Z",
  "answered_at": "2026-09-12T16:00:00Z",
  "updated_at": "2026-09-12T16:00:00Z",
  "question_url": "https://example.com/q/example-question"
}
```

Declined response:

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "status": "declined",
  "title": "Unusual project terms",
  "question": "What is ...?",
  "context": "Optional context",
  "answer": null,
  "declined_reason": "This question requires information I cannot verify.",
  "created_at": "2026-09-12T15:20:30Z",
  "updated_at": "2026-09-12T16:00:00Z",
  "question_url": "https://example.com/q/example-question"
}
```

Error envelope:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The request could not be accepted.",
    "fields": {
      "question": "Question must contain at least 20 characters."
    }
  }
}
```

HTTP errors:

- `400 Bad Request`: malformed JSON.
- `404 Not Found`: unknown/malformed/hidden tracking ID.
- `405 Method Not Allowed`: include an `Allow` header.
- `409 Conflict`: reused idempotency key with different content, or the same
  normalized question was already submitted from this network in the 10-minute
  duplicate window.
- `413 Content Too Large`: raw body above 32 KiB.
- `415 Unsupported Media Type`: wrong POST content type.
- `422 Unprocessable Content`: field validation/consent failure.
- `429 Too Many Requests`: include `Retry-After` and the error envelope.
- `500 Internal Server Error`: generic message plus non-sensitive correlation ID.

Every API response, including errors, uses JSON except an empty OPTIONS response.
Use `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` and handle encoding errors.

API cache policy:

- waiting/declined: `Cache-Control: no-store`;
- answered: `Cache-Control: public, max-age=300`;
- errors: `Cache-Control: no-store`.

CORS for all API responses:

```http
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, Idempotency-Key
Access-Control-Expose-Headers: Location, Retry-After, Idempotency-Replayed
```

Do not use cookies, credentials, or CSRF protection for the public API. It has no
ambient authority; validation and rate limiting are the relevant controls.

### 7.3 Admin routes

| Method | Path | Behaviour |
|---|---|---|
| GET | `/admin/login` | Password form |
| POST | `/admin/login` | Verify password and start session |
| POST | `/admin/logout` | End session |
| GET | `/admin` | Counts and question list/filter |
| GET | `/admin/questions/{numeric_id}` | Review/answer page |
| POST | `/admin/questions/{numeric_id}/answer` | Publish or update answer |
| POST | `/admin/questions/{numeric_id}/decline` | Decline with reason |
| POST | `/admin/questions/{numeric_id}/hide` | Hide immediately |

Admin details:

- All admin routes except login require a valid authenticated session.
- Anonymous access redirects to login for HTML GETs.
- All mutations use POST and CSRF tokens. Never mutate on GET.
- After login, regenerate the session ID.
- Cookie flags: `HttpOnly`, `SameSite=Strict`, `Secure` in production, path
  `/admin`. Public pages do not start an admin session or set this cookie.
- Use `password_verify` against `ADMIN_PASSWORD_HASH`.
- Rate limit login to 10 attempts per 15-minute fixed window per IP hash. A
  successful login may reset that bucket.
- Return the same failure message for all login failures.
- List filters: all, waiting, answered, declined, hidden. Default: waiting first,
  then newest.
- Review page must visually label original question/context as untrusted user
  input. Render it as escaped plain text; never as HTML.
- Answer form contains editable `public_question`, `public_context`, and `answer`.
- Initial public fields are prefilled from originals but not written until
  publication.
- Publishing is transactional: upsert answer, set public fields, clear decline
  reason, set status answered, then commit.
- Editing an existing answer preserves `answers.created_at`.
- Declining requires a public reason and is transactional.
- Hiding requires a confirmation checkbox on the POST form; no JavaScript-only
  confirmation.
- There is no physical delete button.

Admin summary contains only:

- total questions;
- waiting count;
- answered count;
- median response time for answered questions.

If computing a median portably would substantially complicate the initial SQL,
compute it in PHP from answer durations. Dataset size is expected to be tiny.

## 8. Rate limiting and spam controls

### 8.1 Submission limits

Apply to both HTML and API after resolving/HMAC-hashing the IP:

- maximum 5 valid creation attempts in a one-hour fixed window;
- maximum 20 valid creation attempts in a one-day fixed window;
- identical normalized question from the same IP is rejected for 10 minutes.

For the duplicate check, fetch the very small number of recent questions for the
IP and compare a PHP SHA-256 hash of `mb_strtolower(trim(question))`; no extra
database column is required.

Keep parsing bounded by the 32 KiB body limit. A practical order is:

1. reject oversized/wrong content type;
2. resolve and hash the IP;
3. parse and validate input;
4. check idempotency first, so a legitimate replay still retrieves its original
   resource even when the creation limit has subsequently been reached;
5. increment the creation rate buckets;
6. check recent duplicate question content;
7. insert, handling a concurrent idempotency unique-key race by reading and
   returning the winning resource.

HTML honeypot and too-fast submissions use a generic form-level error and never
receive a fake tracking URL. The API does not use these browser-form heuristics.

### 8.2 Polling

- Documentation instructs clients to poll no more than once per 60 seconds and
  increase the interval gradually up to 15 minutes.
- Do not implement per-resource polling state.
- A coarse GET rate limit may be added only if real abuse appears. Do not impede
  search crawlers or normal polling in the initial MVP.
- Hosting/provider-level request limits remain an operational concern outside
  this application.

### 8.3 Content and output safety

- All public and admin user-controlled output uses `htmlspecialchars` with
  `ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8.
- Render plain-text paragraphs with escaping plus `nl2br`; do not accept HTML or
  Markdown.
- JSON-LD values must be produced through `json_encode`, never interpolation.
- XML sitemap values must be XML-escaped.
- Telegram values must be escaped for the selected parse mode.
- Do not auto-link user-provided URLs in the MVP.
- Add server-side length checks even if HTML fields have `maxlength`.
- Never put question content into logs unless needed for an explicit local debug
  session. Production errors should use IDs and exception summaries.
- Do not attempt keyword censorship or AI/prompt-injection detection.

## 9. Telegram notification

After the question transaction commits, synchronously call:

```text
POST https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage
```

Requirements:

- Telegram is notification only. No webhook and no update polling.
- Use cURL when available; implement a small stream-context fallback if allowed
  by the host.
- Connect timeout: 2 seconds. Overall timeout: 4 seconds.
- A Telegram failure must never roll back or hide a stored question and must not
  change the successful HTTP response.
- Log only a sanitized error category/status; never log the bot token.
- Truncate previews so the entire message remains comfortably below Telegram's
  message limit. The admin link is the source of truth for full content.
- Use Telegram HTML parse mode only with correct escaping; plain text is also
  acceptable. No user input may create markup.

Message structure:

```text
New public question

Question:
{question preview}

Context:
{context preview or "Not provided"}

Tracking ID: {public_id}
Source: {html|api}

Answer in admin:
{APP_URL}/admin/questions/{numeric_id}
```

No automatic retry, cron job, queue, or manual resend button is required in the
first implementation. Waiting questions remain visible in admin if Telegram is
unavailable.

## 10. SEO and AI discoverability

### 10.1 Indexing policy

| Page | Robots meta | Sitemap |
|---|---|---|
| Home | `index,follow` | yes |
| `/for-agents` | `index,follow` | yes |
| Human profile | `index,follow` | yes |
| Privacy | `index,follow` | yes |
| Questions archive | `index,follow` | yes |
| Answered question | `index,follow` | yes |
| Waiting question | `noindex,follow` | no |
| Declined question | `noindex,follow` | no |
| Hidden/unknown | 404 | no |
| Admin | auth + `noindex,nofollow` | no |

Do not disallow waiting pages in `robots.txt`: crawlers must be able to fetch the
page to see `noindex`. `robots.txt` is crawler guidance, not access control.

### 10.2 HTML requirements

Every public page must:

- be meaningful without JavaScript;
- use one descriptive `h1` and a logical heading hierarchy;
- use semantic `header`, `nav`, `main`, `article`, `section`, `footer`, `form`,
  `label`, and `time` where appropriate;
- have a unique English `<title>` and meta description;
- have an absolute canonical URL based on `APP_URL`;
- have Open Graph title, description, URL, type, and site name;
- omit fake/default profile imagery; `og:image` may be omitted until a real
  crawlable image exists;
- link visibly to the home page, for-agents page, human profile, answer archive,
  and privacy page;
- include an obvious “Ask your own question” call to action where relevant;
- return correct status codes rather than soft-404 pages.

Answered Q&A pages must include:

- reviewed public question;
- reviewed context when non-empty;
- answer as primary content;
- status `Answered`;
- submission and answer dates;
- answering human name linked to profile;
- a short explanation that a real human supplied the answer;
- link to ask another question.

Waiting pages must include the polling/status URL as copyable plain text and an
ordinary link, current status, submitted question/context, and instructions to
check later.

### 10.3 Metadata patterns

Use concise, content-derived values. Examples:

- Home title: `Ask a Human When the Web Has No Answer`
- Agents title: `API for AI Agents to Ask a Human`
- Profile title: `{Human name} — Human Answerer in {location}`
- Q&A title: `{title, else short question} — Answered by {human name}`
- Waiting title: `Question waiting for a human answer`

Descriptions should be derived from visible page content, not keyword lists.
Never include secrets/configuration in metadata.

### 10.4 JSON-LD

Generate server-side JSON-LD that exactly matches visible content.

Home:

- `WebSite` with name, URL, description, and publisher/site identity only when
  the available properties are truthful.
- `WebPage` may reference the `WebSite` via `isPartOf`.

Human profile:

- `ProfilePage` with `mainEntity` `Person`.
- Person fields: name, alternate/native name, URL, description, home location,
  `knowsAbout`, and `sameAs` only for verified identity profiles.
- Company/work URLs are affiliation links, not automatically `sameAs`
  identity URLs. Represent them as a Person `affiliation` pointing to an
  `Organization` with the verified name and URL. `sameAs` contains only
  identity URLs the human confirmed (`same_as_json`). Do not misuse `sameAs`
  or invent a job-title/employment relationship.
- Do not add an `image` until a real profile image is supplied.

Answered page:

- Use a `WebPage` whose `mainEntity` is a Schema.org `Question` with an
  `acceptedAnswer` `Answer` and the answering `Person` as author.
- Do **not** declare Google `QAPage` rich-result eligibility. Google's current
  QAPage guidance says users must be able to submit alternative answers, which
  this service does not support.
- Do not use `FAQPage` for single Q&A pages or site-written marketing copy.

Waiting/declined pages:

- Basic `WebPage` is sufficient. Do not emit an empty/fake `Answer`.

Relevant references:

- Google Q&A guidance: <https://developers.google.com/search/docs/appearance/structured-data/qapage>
- Google ProfilePage guidance: <https://developers.google.com/search/docs/appearance/structured-data/profile-page>
- General structured-data rules: <https://developers.google.com/search/docs/appearance/structured-data/sd-policies>
- Robots/noindex distinction: <https://developers.google.com/search/docs/crawling-indexing/robots/intro>

### 10.5 Sitemap

- Dynamic `/sitemap.xml`, `Content-Type: application/xml; charset=utf-8`.
- Include canonical absolute URLs for home, agents, profile, privacy, archive,
  and every answered question.
- Use the relevant `updated_at` as `<lastmod>` in W3C/ISO format.
- Do not include API, OpenAPI, `llms.txt`, admin, waiting, declined, hidden, or
  error URLs.
- It is acceptable to generate the sitemap from the database on each request for
  MVP scale. No cached file or cron is required.
- If volume later grows beyond roughly 10,000 answered pages, revisit paging or
  a sitemap index; do not build it now.

### 10.6 `robots.txt`

Serve plain text equivalent to:

```text
User-agent: *
Allow: /
Disallow: /admin

Sitemap: https://canonical-app-url.example/sitemap.xml
```

Generate the sitemap line from `APP_URL`, or produce the deploy-time static file
from configuration. Do not maintain per-vendor bot lists. This intentionally
allows generic search and AI crawlers.

### 10.7 `llms.txt`

Implement `/llms.txt` as a small Markdown-like plain-text resource containing:

- service name and one-sentence purpose;
- explicit sentence that an AI agent may ask a human when web search fails;
- who answers and his location/topics;
- warning that questions and answers are public;
- links to `/for-agents`, `/openapi.yaml`, human profile, archive, and HTML form;
- POST and GET endpoint URLs;
- instruction to save `status_url` and poll no more often than every 60 seconds.

`llms.txt` is a low-cost supplementary hint, not a guaranteed discovery
standard. Do not claim that all LLMs consume it. Reference proposal:
<https://llmstxt.org/>.

### 10.8 OpenAPI

- Provide static `openapi.yaml` using OpenAPI 3.1.
- Use a relative OpenAPI server URL (`url: /`) so the static document remains
  correct on local and production hosts. Serve the repository-root file through
  the route rather than placing secrets or environment-specific values in it.
- Define create/get operations, request/response schemas, examples, status enums,
  `Idempotency-Key`, validation errors, rate limiting, public consent, and CORS.
- Link it visibly from `/for-agents` and in `<link rel="alternate"
  type="application/yaml">` where appropriate.
- Do not add obsolete ChatGPT plugin manifests or claim automatic tool import.

### 10.9 Discovery launch checklist

Code cannot prove the hypothesis without distribution. Production launch must:

1. Set a canonical HTTPS domain and redirect HTTP/alternate host variants.
2. Verify the domain in Google Search Console and Bing Webmaster Tools.
3. Submit `/sitemap.xml` to both.
4. Request indexing for the home, `/for-agents`, profile, and initial answers.
5. Publish 5–10 truthful seed Q&A pages answered by real humans. They must be
   clearly genuine site-created starter questions, not fabricated agent traffic.
6. Obtain a few relevant, honest external links, including a link from an
   existing owned site/profile if available.
7. Check rendered HTML, canonical tags, noindex transitions, sitemap updates,
   Rich Results Test, Schema Markup Validator, and mobile usability.
8. Inspect server logs/Search Console over time rather than assuming `llms.txt`
   caused discovery.

The largest experiment risks are outside implementation: a new domain has no
authority; many agents can only GET pages; some cannot retain state across runs;
some require human confirmation before POST; and agents may ignore `llms.txt` or
OpenAPI entirely. Document these limitations honestly.

## 11. Profile content policy

The visible biography must communicate who answers and what is reasonable to
ask without exaggeration. Profile data belongs to each human; this repository
ships only a clearly fictional example (`ivan-petrov` in `database/seed.sql`).
A production instance fills real profiles from what each person confirmed
about themselves — through the join form, the Telegram self-service edit
link, or an admin.

Rules for any profile:

- Publish only facts the person confirmed. Never extrapolate a profile from
  scraped pages, and never invent one.
- Respect third parties: no facts about family members, colleagues, or
  clients without their consent; keep minors' personal details (names,
  birth years, photos) out of public profiles entirely.
- `same_as_json` holds identity URLs (personal site, verified social
  profiles) only; work/company URLs go to `links_json`/`human_resources`.
- The profile must explicitly say questions on other subjects are also
  welcome and that the human may decline when they cannot provide a reliable
  or appropriate answer.

Do not infer or add:

- years of experience;
- a seniority label beyond the confirmed headline;
- conference dates/talk titles;
- employer/client relationships;
- specific local availability or guaranteed physical errands;
- academic credentials;
- social identity URLs;
- claims that the person is an authority/expert in every listed topic.

## 12. Privacy and measurement

Persist only the requested experiment data:

- question creation date;
- submission source (`html` or `api`);
- user agent;
- referrer when supplied by the client;
- HMAC IP hash for abuse controls, not raw IP;
- answer creation date, from which response time is computed.

Do not fingerprint clients, detect “real AI,” set public tracking cookies, or add
third-party analytics in the MVP. The admin authentication session is the only
required cookie.

The `/privacy` page must explain:

- questions, reviewed context, and answers are public and may be indexed;
- user agent, referrer, submission source, dates, and a one-way keyed IP hash are
  stored for experiment measurement and abuse prevention;
- the application does not intentionally store raw IP addresses, while hosting
  provider/server logs may exist outside the application;
- secrets and other people's personal data must not be submitted;
- a request for removal can be sent to `CONTACT_EMAIL`.

Production launch is blocked until a real `CONTACT_EMAIL` is configured. Do not
invent or hard-code an address.

## 13. Error handling and security headers

Recommended headers on all responses where applicable:

```text
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: DENY
Permissions-Policy: camera=(), microphone=(), geolocation=()
Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'
```

Prefer no inline executable scripts/styles so CSP stays simple. Generate a
per-response random nonce, include it in `script-src`, and put that nonce on the
server-rendered `application/ld+json` element. Do not enable arbitrary inline
JavaScript merely to make JSON-LD convenient.

Additional rules:

- Production PHP settings: `display_errors=0`, logging enabled.
- Set PDO exception mode, associative fetch mode, emulated prepares off.
- Database credentials must have only needed permissions on this database.
- Enforce HTTPS operationally and use secure cookies.
- Reject control characters in headers and never reflect arbitrary headers.
- Protect redirects: redirect paths are generated internally, never taken from
  user input.
- Do not expose sequential IDs, SQL errors, config values, tokens, or stack
  traces.
- Use constant-time/password library primitives; do not manually hash passwords.
- Regenerate CSRF token at login; compare with `hash_equals`.

## 14. Presentation

The frontend should be intentionally modest and credible:

- responsive single-column content with a wider form/article region;
- system font stack;
- good contrast and visible keyboard focus;
- labels above fields and errors associated with fields;
- no external fonts, icon kits, trackers, cookie banner, animation library, or
  build step;
- status badges must not rely on colour alone;
- code/API examples use horizontally scrollable `<pre><code>`;
- line length suitable for reading long answers;
- a small experimental-startup notice visible on home and `/for-agents`.

Do not add fake testimonials, usage counts, online indicators, response-time
claims, customer logos, or “trusted by” sections.

## 15. Implementation order

The next session should implement in this order:

1. Create config loader, bootstrap, PDO connection, error handling, and router.
2. Initialize the schema (`database/migrations/001_initial.sql`) and load the
   example seed data.
3. Implement repositories and question creation transaction.
4. Implement API POST/GET with validation, errors, idempotency, CORS, and limits.
5. Implement Telegram best-effort notification.
6. Implement HTML landing/form/tracking/archive/profile/privacy pages.
7. Implement admin authentication, list, review, publish/edit, decline, and hide.
8. Add metadata, canonical handling, JSON-LD, robots, sitemap, `llms.txt`, and
   OpenAPI.
9. Add CSS and accessibility polish.
10. Execute every acceptance scenario in `docs/ACCEPTANCE_TESTS.md`.
11. Write final shared-hosting deployment instructions with exact commands or
    control-panel steps.

Do not spend time on generic framework construction before the first complete
question-to-answer flow works.

## 16. Definition of done

The MVP is done only when all of these are true:

- A clean database can be initialized from committed SQL.
- A browser user can submit a consented public question.
- An API client can submit the documented JSON and immediately receive stable
  question/status URLs.
- Telegram receives a safe notification with a working admin link.
- Telegram failure does not lose or misreport a question.
- An authenticated human (or the platform owner on their behalf) can publish
  an answer.
- The same status API changes from waiting to answered without changing ID.
- The public tracking page changes from noindex waiting state to a useful,
  indexable answer page.
- Sitemap membership changes correctly after publication.
- Unknown and hidden IDs do not leak existence.
- API and HTML content remain safely escaped with adversarial input.
- Public pages work without JavaScript and link to each other.
- Profile content contains only owner-confirmed facts.
- OpenAPI, `llms.txt`, `/for-agents`, robots, canonical tags, and JSON-LD are
  available and mutually consistent.
- The project can be deployed to an ordinary PHP host without a long-running
  process, root access, Node.js, or Redis.
