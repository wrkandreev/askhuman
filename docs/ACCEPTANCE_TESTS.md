# MVP acceptance tests

This checklist is normative. The implementation session must execute relevant
automated checks and manually inspect rendered pages. It should report any item
that cannot be verified locally because production credentials/domain are not
yet available.

Use a temporary/local database and fake configuration. Never use real Telegram
credentials in committed tests.

## 1. Fresh installation

- [ ] `database/migrations/001_initial.sql` runs successfully on an empty MySQL
  database.
- [ ] Running the documented seed operation creates the example human
  (`ivan-petrov`).
- [ ] Re-running a documented install step either fails clearly or is safely
  idempotent; it never silently duplicates seed rows.
- [ ] Application starts with PHP 8.2 and no runtime Composer install.
- [ ] Missing production `APP_URL`, DB config, admin hash, rate secret, or contact
  email produces a clear safe configuration failure.
- [ ] `APP_DEBUG=false` never renders a stack trace or secret.

## 2. Public HTML submission

- [ ] GET `/` returns `200`, a complete SSR form, publication warning, unchecked
  consent box, signed form token, and no JavaScript dependency.
- [ ] A valid consented form submission creates one question with source `html`,
  `waiting_for_human`, public visibility, a 32-character lowercase hex public ID,
  and metadata.
- [ ] Success returns `303` to the canonical public question URL.
- [ ] Public IDs from multiple submissions are unique and are not sequential.
- [ ] Missing question returns `422`, preserves escaped input, and creates no row.
- [ ] A 19-character question is rejected; a 20-character question is accepted.
- [ ] A question over 4,000 characters is rejected.
- [ ] Context over 8,000 characters is rejected.
- [ ] Missing consent is rejected and the checkbox remains unchecked.
- [ ] Invalid/expired/tampered form token is rejected and creates no row.
- [ ] Filled honeypot creates no row and does not reveal the detection rule.
- [ ] An implausibly fast HTML submission gets the documented generic error.
- [ ] HTML containing `<script>`, quotes, ampersands, malformed UTF-8, and Unicode
  never executes or breaks the page.

## 3. API creation

- [ ] OPTIONS returns `204` and the documented CORS headers.
- [ ] Valid JSON with `question`, optional `context`, and `public: true` returns
  `201`, JSON content type, `Location`, no-store, ID, waiting status, absolute
  question URL, absolute status URL, and exact tracking message.
- [ ] The returned status URL is immediately readable.
- [ ] All generated absolute URLs use configured `APP_URL`, even with a malicious
  request `Host` header.
- [ ] Missing/false/string-valued `public` returns `422`.
- [ ] Missing/short/long/non-string question returns `422` with field details.
- [ ] Non-string context returns `422`.
- [ ] Optional `title`: absent/blank stores `NULL`; a 3–150 character title is
  echoed by the status endpoint, becomes the answer page `h1` and `<title>`,
  and seeds the question URL slug; shorter/longer/non-string `title` returns
  `422` with field details.
- [ ] Malformed JSON returns `400`, not `500`.
- [ ] JSON array/scalar root returns `400` or documented `422`, consistently.
- [ ] Wrong/missing content type returns `415`.
- [ ] `POST /api/humans` creates an `awaiting_claim` draft: 201, final slug,
  `claim_url` with a 32-hex token; the profile URL 404s until claimed and the
  draft accepts no questions.
- [ ] Claiming via the Telegram deep link payload (`/start claim_<token>`)
  binds the chat, activates the profile, and invalidates the claim link.
- [ ] A paused human returns `409 human_unavailable` for new questions (API)
  and hides the web ask form; published answers stay online.
- [ ] Telegram: `/my`, `/edit` (single-use 30-minute link; saving invalidates
  it), `/pause`, `/resume`, `/delete` + `/confirm_delete` (two-step) work;
  `/unpublish` as a reply hides the answer and returns the question to
  `waiting_for_human`; re-answering republishes it.
- [ ] Soft-deleted profile: page 404s, catalog/sitemap/llms.txt exclude it,
  published question pages keep the human's name as plain text.
- [ ] Body over 32 KiB returns `413` before JSON parsing/storage.
- [ ] Unknown input fields cannot set ID, human, answer, status, or privacy.
- [ ] Every API error is a valid JSON error envelope with no HTML/stack trace.
- [ ] API response handles Cyrillic/emoji without escaped-gibberish corruption.

## 4. Idempotency

- [ ] First valid request with `Idempotency-Key` returns `201` and notifies once.
- [ ] Same IP/key/content replay returns `200`, same ID/URLs,
  `Idempotency-Replayed: true`, creates no row, and sends no notification.
- [ ] Same IP/key with changed content returns `409`.
- [ ] Same literal key from another IP does not collide.
- [ ] Invalid, empty, too-long, or non-printable key is rejected safely.
- [ ] Concurrent same-key requests create at most one question.

## 5. Rate limiting and request metadata

- [ ] Five allowed submissions per hour succeed; the next applicable attempt is
  rejected with `429` and `Retry-After`.
- [ ] Daily limit rejects the 21st applicable submission.
- [ ] A duplicate normalized API question from one IP within 10 minutes returns
  `409`; the HTML form shows a generic form error. Neither creates a new row.
- [ ] Limits apply to both HTML and API sources.
- [ ] Database stores a 64-character HMAC hash, never the raw IP.
- [ ] Spoofed `X-Forwarded-For` is ignored from an untrusted peer.
- [ ] Configured trusted proxy handling selects the documented client address.
- [ ] User agent and referrer are captured, bounded, and escaped on any display.
- [ ] Expired limiter buckets can be cleaned without cron.

## 6. Telegram

- [ ] A successful new question performs one Telegram send after DB commit.
- [ ] Message includes safe previews, context/empty marker, tracking ID, source,
  and correct admin URL.
- [ ] Telegram markup injection in question/context is harmless.
- [ ] Bot token never appears in logs, HTML, API JSON, or exception output.
- [ ] Timeout/network/API failure still returns successful question creation and
  leaves the question visible in admin.
- [ ] An idempotent replay does not send again.
- [ ] Local testing can replace/mock notification without a live bot token.

## 7. Tracking pages and API status

- [ ] Waiting page returns `200`, displays escaped question/context, status,
  tracking ID, status URL, dates, and save/poll instructions.
- [ ] Waiting page has `noindex,follow`, canonical, and is absent from sitemap.
- [ ] Valid ID with missing/wrong slug redirects `301` to canonical slug.
- [ ] Unknown/malformed ID returns a true `404`.
- [ ] API waiting response matches OpenAPI and contains `answer: null` plus
  `poll_after_seconds: 60`.
- [ ] Declined HTML/API expose the public reason, tell clients to stop polling,
  remain noindex, and are absent from sitemap.
- [ ] Hidden HTML/API both return indistinguishable `404` responses.

## 8. Admin authentication and safety

- [ ] Anonymous admin access redirects to login and leaks no question content.
- [ ] Correct password authenticates using `password_verify` and regenerates the
  session ID.
- [ ] Incorrect password uses the same generic error for every failure.
- [ ] Login limiting activates after 10 attempts in 15 minutes.
- [ ] Admin cookie is HttpOnly, SameSite Strict, admin-path scoped, and Secure in
  production.
- [ ] Visiting public pages does not start an admin session or set a public
  tracking cookie.
- [ ] Logout is POST + CSRF, destroys session, and cannot be triggered by GET.
- [ ] Every admin mutation rejects missing/invalid CSRF.
- [ ] Waiting, answered, declined, and hidden list filters work.
- [ ] Original content is clearly marked untrusted and rendered as plain text.
- [ ] No public action exposes numeric database IDs.
- [ ] Admin pages use `noindex,nofollow` and `/admin` is absent from sitemap.

## 9. Publish/edit/decline/hide

- [ ] Answer page pre-fills reviewed public question/context from originals.
- [ ] Publishing empty public question or answer is rejected.
- [ ] Valid publication atomically creates answer, public fields, answered status,
  and timestamps.
- [ ] Immediately after publication, API returns answer and reviewed public text.
- [ ] Public page becomes useful answered content with `index,follow`.
- [ ] Answered page enters sitemap with correct canonical and lastmod.
- [ ] Original submitted text remains unchanged after public editing.
- [ ] Editing an answer updates `updated_at` but preserves first `created_at` and
  response-time metric.
- [ ] Decline requires a reason and never creates a fake answer.
- [ ] Hide requires explicit confirmation and immediately produces public 404.
- [ ] There is no public/admin physical delete control.

## 10. Profile and factual integrity

- [ ] Every published profile fact was confirmed by the profile's owner.
- [ ] The Latin and native-spelling names are both rendered correctly.
- [ ] Profile links are ordinary anchors that work.
- [ ] Topic list allows other questions and does not claim universal expertise.
- [ ] No facts about third parties or minors are published without consent.
- [ ] Empty projects/links/facts sections are not rendered as fake placeholders.
- [ ] No fake image, social profile, experience length, client, award, education,
  availability, testimonial, or SLA appears anywhere.

## 11. SEO and machine discovery

- [ ] All public pages contain useful primary content in initial HTML.
- [ ] Titles, descriptions, canonicals, Open Graph, headings, and internal links
  are page-specific and based on visible content.
- [ ] Home links to agents, profile, archive, privacy, form, and latest answers.
- [ ] Every answered Q&A links to the profile and ask form.
- [ ] Archive contains only answered questions, 20 per page, with working links.
- [ ] `/for-agents` contains the required opening sentence, warning, curl, JSON,
  status definitions, polling guidance, OpenAPI link, and human link.
- [ ] `/llms.txt` returns plain text with consistent absolute URLs and truthful
  limitations.
- [ ] `/openapi.yaml` is valid OpenAPI 3.1 and exactly matches implemented API.
- [ ] `/robots.txt` allows public crawling, disallows `/admin`, and gives the
  canonical sitemap URL.
- [ ] `/sitemap.xml` is well-formed XML and contains only allowed canonical URLs.
- [ ] Profile JSON-LD is valid `ProfilePage` + `Person` and matches visible facts.
- [ ] Answered-page JSON-LD is `WebPage` with `Question`/`acceptedAnswer` and does
  not claim `QAPage` rich-result eligibility.
- [ ] No `FAQPage`, fake answer markup, obsolete AI plugin manifest, or invented
  structured-data property is present.
- [ ] JSON-LD remains valid and safe when public text contains `</script>`.
- [ ] Waiting-to-answered transition removes noindex and updates sitemap without a
  code/deploy action.

## 12. HTTP and rendering quality

- [ ] Unsupported methods return `405` plus correct `Allow`.
- [ ] API 404 is JSON; public-page 404 is semantic HTML.
- [ ] Security headers are present and do not break forms or JSON-LD.
- [ ] CSP does not permit arbitrary inline executable JavaScript.
- [ ] UTF-8 headers and database connection settings are consistent.
- [ ] Dates use UTC ISO 8601 in API and valid `<time datetime>` in HTML.
- [ ] Layout is usable on a narrow mobile viewport.
- [ ] Keyboard focus is visible; labels/errors are associated with controls.
- [ ] Primary flows remain usable with JavaScript disabled.
- [ ] There are no external fonts, trackers, framework CDNs, or build artifacts.

## 13. Metrics

- [ ] Admin shows total, waiting, answered, and median answer time.
- [ ] Counts include source/date data correctly and do not pretend to classify
  genuine AI clients.
- [ ] Response time uses question creation to first answer creation, not last edit.
- [ ] No third-party analytics or public tracking cookies are introduced.

## 14. Deployment handoff

- [ ] Documentation covers standard `public/` document-root deployment.
- [ ] Documentation covers a shared host where public files must live in
  `public_html` and private application files live outside it, using
  `APP_BASE_PATH`.
- [ ] It lists required PHP extensions and writable/session/log assumptions.
- [ ] It shows how to generate `ADMIN_PASSWORD_HASH` and a random
  `RATE_LIMIT_SECRET` without committing either.
- [ ] It explains migration, seed, Telegram test, HTTPS, canonical redirect,
  Search Console, Bing, and sitemap submission.
- [ ] `.gitignore` excludes `config/local.php`, environment files, logs, sessions,
  editor files, and other secrets/runtime data.
- [ ] No secret appears in Git diff/history or sample config.

## 15. End-to-end release scenario

Before calling the MVP complete, perform this full sequence:

1. Start from an empty database and install/seed.
2. Submit one question through API with an idempotency key.
3. Verify `201`, Telegram attempt, DB metadata, waiting HTML, waiting API,
   noindex, and absence from sitemap.
4. Replay the same API request and verify no duplicate/notification.
5. Log into admin and review the original untrusted text.
6. Edit the public context, publish a plain-text answer, and log out.
7. Verify answered API, answered HTML, attribution, dates, indexability, JSON-LD,
   canonical, archive listing, home listing, and sitemap membership.
8. Edit the answer and verify preserved first answer time plus updated lastmod.
9. Submit a second question, decline it, and verify terminal noindex behaviour.
10. Submit a third question, hide it, and verify indistinguishable HTML/API 404.
11. Repeat adversarial cases with HTML/script strings, Unicode, hostile Host,
    spoofed forwarding headers, Telegram failure, and rate-limit exhaustion.

The final implementation report must list commands/tests run, what passed, and
which production-only checks remain pending because credentials/domain were not
provided.
