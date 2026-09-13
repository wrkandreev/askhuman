<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\HumanRepository;
use App\Support\Config;
use App\Support\Database;
use App\Support\Http;

final class HumanService
{
    public function __construct(
        private HumanRepository $humans,
        private RateLimiter $rateLimiter,
        private TelegramNotifier $telegram,
        private Config $config,
    ) {
    }

    /**
     * Validate and normalize a public join application.
     *
     * @return array{name:string,name_native:string,location:string,headline:string,bio:string,
     *   expertise:list<string>,links:list<array{label:string,url:string}>}
     */
    public function validateApplication(
        mixed $name,
        mixed $nameNative,
        mixed $location,
        mixed $headline,
        mixed $bio,
        mixed $expertise,
        mixed $links,
    ): array {
        $fields = [];
        $name = $this->textField($name, 2, 100, 'name', 'Name must contain 2–100 characters.', $fields);
        $nameNative = $this->textField($nameNative, 0, 100, 'name_native', 'Native name must not exceed 100 characters.', $fields);
        $location = $this->textField($location, 0, 100, 'location', 'Location must not exceed 100 characters.', $fields);
        $headline = $this->textField($headline, 10, 120, 'headline', 'Headline must contain 10–120 characters.', $fields);
        $bio = $this->textField($bio, 50, 2000, 'bio', 'Describe what you can answer in 50–2,000 characters.', $fields);

        $expertiseList = [];
        if ($expertise !== null && $expertise !== '') {
            if (!is_string($expertise)) {
                $fields['expertise'] = 'Topics must be text, one per line.';
            } else {
                foreach (preg_split('/\r?\n/', trim($expertise)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $len = mb_strlen($line);
                    if ($len < 2 || $len > 60) {
                        $fields['expertise'] = 'Each topic must contain 2–60 characters.';
                        break;
                    }
                    $expertiseList[] = $line;
                }
                if (count($expertiseList) > 15) {
                    $fields['expertise'] = 'List at most 15 topics.';
                }
            }
        }

        $linkList = [];
        if ($links !== null && $links !== '') {
            if (!is_string($links)) {
                $fields['links'] = 'Links must be text, one per line.';
            } else {
                foreach (preg_split('/\r?\n/', trim($links)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (count($linkList) >= 5) {
                        $fields['links'] = 'List at most 5 links.';
                        break;
                    }
                    $label = '';
                    if (str_contains($line, '|')) {
                        [$label, $line] = array_map('trim', explode('|', $line, 2));
                        $label = mb_substr($label, 0, 80);
                    }
                    $url = $line;
                    if (!preg_match('#^https?://[^\s<>"\']+$#iu', $url) || mb_strlen($url) > 255) {
                        $fields['links'] = 'Each link must be a full http(s) URL.';
                        break;
                    }
                    $linkList[] = ['label' => $label, 'url' => $url];
                }
            }
        }

        if ($fields !== []) {
            throw new DomainException('validation_failed', 'The application could not be accepted.', 422, $fields);
        }

        return [
            'name' => $name,
            'name_native' => $nameNative,
            'location' => $location,
            'headline' => $headline,
            'bio' => $bio,
            'expertise' => $expertiseList,
            'links' => $linkList,
        ];
    }

    /**
     * @param array<string,string> $fields error accumulator
     */
    private function textField(mixed $value, int $min, int $max, string $key, string $message, array &$fields): string
    {
        if ($value === null) {
            // Optional fields may be absent entirely.
            if ($min > 0) {
                $fields[$key] = $message;
            }
            return '';
        }
        if (!is_string($value)) {
            $fields[$key] = $message;
            return '';
        }
        $value = trim(Http::cleanUtf8($value) ?? '');
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            $fields[$key] = $message;
            return '';
        }
        return $value;
    }

    /**
     * Create a pending application after rate limiting.
     *
     * @param array<string,mixed> $input validated application data
     * @return array<string,mixed> stored row
     */
    public function createApplication(array $input, string $ip): array
    {
        $ipHash = Http::hmac($ip, $this->config);
        $hourKey = hash_hmac('sha256', 'join-hour:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $dayKey = hash_hmac('sha256', 'join-day:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $hour = $this->rateLimiter->hit($hourKey, 3600);
        $day = $this->rateLimiter->hit($dayKey, 86400);
        if ($hour > 3 || $day > 10) {
            throw new DomainException(
                'rate_limited',
                'Too many applications were submitted from this network. Try again later.',
                429,
                [],
                ['Retry-After' => (string) 3600]
            );
        }

        $publicId = bin2hex(random_bytes(16));
        $slug = $this->uniqueSlug($this->makeSlug((string) $input['name']), $publicId);

        $row = $input + ['slug' => $slug, 'public_id' => $publicId];
        $id = $this->humans->insertApplication($row);
        $stored = $this->humans->findById($id);

        try {
            $this->telegram->notifyNewApplication($stored);
        } catch (\Throwable) {
            error_log('askahuman telegram: application notification error for ' . $publicId);
        }

        return $stored;
    }

    public function makeSlug(string $name): string
    {
        $slug = trim($name);
        if (mb_strlen($slug) > 90) {
            $slug = mb_substr($slug, 0, 90);
        }
        $slug = \App\Support\Translit::toLatin($slug);
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim(mb_strtolower($slug), '-');
        return $slug === '' ? 'human' : $slug;
    }

    /**
     * Slugs that must never become a root-level endpoint URL: they would
     * shadow existing routes (/join, /privacy, ...) or platform paths.
     */
    private const RESERVED_SLUGS = [
        'questions', 'humans', 'join', 'connect', 'q', 'admin', 'api',
        'assets', 'for-agents', 'privacy', 'openapi.yaml', 'llms.txt',
        'robots.txt', 'sitemap.xml', 'index.php', 'favicon.ico', 'ask',
    ];

    private function uniqueSlug(string $slug, string $publicId): string
    {
        $base = $slug;
        $i = 1;
        while ($this->humans->findBySlug($slug) !== null || in_array($slug, self::RESERVED_SLUGS, true)) {
            $slug = $base . '-' . substr($publicId, 0, 6);
            $i++;
            if ($i > 3) {
                $slug = $base . '-' . substr($publicId, 0, 12);
                break;
            }
        }
        return $slug;
    }

    /**
     * Admin: update editable fields. Same shape as validateApplication plus
     * resources and per-human Telegram delivery credentials.
     *
     * @return array<string,mixed>
     */
    public function validateProfileUpdate(array $post): array
    {
        $data = $this->validateApplication(
            $post['name'] ?? null,
            $post['name_native'] ?? null,
            $post['location'] ?? null,
            $post['headline'] ?? null,
            $post['bio'] ?? null,
            $post['expertise'] ?? null,
            $post['links'] ?? null,
        );
        $data['projects'] = $this->parseLinkList($post['projects'] ?? null, 'projects');
        $data['resources'] = $this->parseResourceList($post['resources'] ?? null);
        $data['telegram_bot_token'] = $this->optionalField($post['telegram_bot_token'] ?? null, 256);
        $data['telegram_chat_id'] = $this->optionalField($post['telegram_chat_id'] ?? null, 64);
        return $data;
    }

    private function optionalField(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Public wrapper: resource lines from the self-service edit form
     * ("Title | URL | Description").
     *
     * @return list<array{url:string,title:string,description:?string}>
     */
    public function parseResourcesForEdit(mixed $value): array
    {
        return $this->parseResourceList($value);
    }

    /**
     * Resource lines: "Title | URL | Description" (description optional).
     *
     * @return list<array{url:string,title:string,description:?string}>
     */
    private function parseResourceList(mixed $value): array
    {
        $list = [];
        if (is_string($value) && $value !== '') {
            foreach (preg_split('/\r?\n/', trim($value)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                $title = '';
                $url = '';
                $description = null;
                if (count($parts) >= 2) {
                    $title = mb_substr($parts[0], 0, 190);
                    $url = $parts[1];
                    if (isset($parts[2]) && $parts[2] !== '') {
                        $description = mb_substr($parts[2], 0, 500);
                    }
                } else {
                    $url = $parts[0];
                }
                if (preg_match('#^https?://[^\s<>"\']+$#iu', $url) === 1 && mb_strlen($url) <= 2048) {
                    $list[] = ['url' => $url, 'title' => $title !== '' ? $title : $url, 'description' => $description];
                }
            }
        }
        return $list;
    }

    /**
     * @return list<array{label:string,url:string}>
     */
    private function parseLinkList(mixed $value, string $field): array
    {
        // Used only from admin edit; validation already ran via validateApplication
        // for links. Projects reuse the same "Label | URL" format.
        $list = [];
        if (is_string($value) && $value !== '') {
            foreach (preg_split('/\r?\n/', trim($value)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $label = '';
                if (str_contains($line, '|')) {
                    [$label, $line] = array_map('trim', explode('|', $line, 2));
                    $label = mb_substr($label, 0, 80);
                }
                if (preg_match('#^https?://[^\s<>"\']+$#iu', $line) === 1 && mb_strlen($line) <= 255) {
                    $list[] = ['label' => $label, 'url' => $line];
                }
            }
        }
        return $list;
    }

    /* ---------------- Telegram pairing (self-serve onboarding) ---------------- */

    /**
     * Issue a one-time pairing code (valid 48 h) for a pending application.
     */
    public function issuePairingCode(array $human): string
    {
        $code = bin2hex(random_bytes(6)); // 12 hex chars = 48 bits
        $this->humans->setPairingCode((int) $human['id'], $code);
        return $code;
    }

    /**
     * Bind a chat to a pending application by pairing code and activate the
     * profile. Returns the bound human row or null when the code is unknown
     * or expired.
     *
     * @return array<string,mixed>|null
     */
    public function bindByPairingCode(string $code, string $chatId): ?array
    {
        $code = trim($code);
        if (preg_match('/^[0-9a-f]{12}$/', $code) !== 1) {
            return null;
        }
        $h = $this->humans->findByPairingCode($code);
        if ($h === null) {
            return null;
        }
        $this->humans->bindTelegram((int) $h['id'], $chatId);
        return $this->humans->findById((int) $h['id']);
    }

    public function approve(int $id): void
    {
        $this->humans->setStatus($id, 'approved', true, null);
    }

    public function decline(int $id, string $note): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw new DomainException('validation_failed', 'The application could not be accepted.', 422, [
                'review_note' => 'A short public reason is required (1–1,000 characters).',
            ]);
        }
        $this->humans->setStatus($id, 'declined', false, $note);
    }

    public function hide(int $id): void
    {
        $this->humans->setStatus($id, 'hidden', false, null);
    }

    /* ---------------- Owner moderation (platform chat) ---------------- */

    /**
     * @return list<array<string,mixed>>
     */
    public function listHidden(): array
    {
        return $this->humans->listHidden();
    }

    /**
     * Hide an approved profile by slug (quality moderation). Returns the
     * hidden row, or null when the slug is not a live profile.
     *
     * @return array<string,mixed>|null
     */
    public function hideBySlug(string $slug): ?array
    {
        $h = $this->humans->findBySlug(trim($slug));
        if ($h === null || $h['status'] !== 'approved' || empty($h['is_active'])) {
            return null;
        }
        $this->hide((int) $h['id']);
        return $this->humans->findById((int) $h['id']);
    }

    /**
     * Restore a hidden profile to the live catalog.
     *
     * @return array<string,mixed>|null
     */
    public function unhideBySlug(string $slug): ?array
    {
        $h = $this->humans->findBySlug(trim($slug));
        if ($h === null || $h['status'] !== 'hidden') {
            return null;
        }
        $this->humans->setStatus((int) $h['id'], 'approved', true, null);
        return $this->humans->findById((int) $h['id']);
    }

    /* ---------------- Agent-created drafts (awaiting_claim) ---------------- */

    public const CLAIM_TTL_SECONDS = 48 * 3600;
    public const EDIT_TTL_SECONDS = 1800; // 30 minutes
    public const DELETE_CONFIRM_SECONDS = 1800; // 30 minutes

    /**
     * Validate agent-supplied draft fields. Only `name` is required: the
     * profile is a draft the human can complete after claiming it.
     *
     * @return array{name:string,name_native:string,location:string,headline:string,bio:string,
     *   expertise:list<string>,links:list<array{label:string,url:string}>,slug:?string}
     */
    public function validateDraftInput(array $body): array
    {
        $fields = [];
        $name = $this->textField($body['name'] ?? null, 2, 100, 'name', 'Name must contain 2–100 characters.', $fields);
        $nameNative = $this->textField($body['name_native'] ?? null, 0, 100, 'name_native', 'Native name must not exceed 100 characters.', $fields);
        $location = $this->textField($body['location'] ?? null, 0, 100, 'location', 'Location must not exceed 100 characters.', $fields);
        $headline = $this->textField($body['headline'] ?? null, 0, 120, 'headline', 'Headline must not exceed 120 characters.', $fields);
        $bio = $this->textField($body['bio'] ?? null, 0, 2000, 'bio', 'Bio must not exceed 2,000 characters.', $fields);

        $expertise = $body['expertise'] ?? null;
        $expertiseList = [];
        if (is_array($expertise)) {
            foreach ($expertise as $item) {
                if (!is_string($item)) {
                    $fields['expertise'] = 'Topics must be strings.';
                    break;
                }
                $item = trim(Http::cleanUtf8($item) ?? '');
                if ($item === '') {
                    continue;
                }
                $len = mb_strlen($item);
                if ($len < 2 || $len > 60) {
                    $fields['expertise'] = 'Each topic must contain 2–60 characters.';
                    break;
                }
                $expertiseList[] = $item;
            }
            if (!isset($fields['expertise']) && count($expertiseList) > 15) {
                $fields['expertise'] = 'List at most 15 topics.';
            }
        } elseif (is_string($expertise) && $expertise !== '') {
            // Reuse the newline-separated validation from applications.
            try {
                $expertiseList = $this->validateApplication(null, null, null, null, null, $expertise, null)['expertise'];
            } catch (DomainException $e) {
                $fields['expertise'] = $e->fields['expertise'] ?? 'Topics must be text, one per line.';
            }
        } elseif ($expertise !== null) {
            $fields['expertise'] = 'Topics must be an array of strings or newline-separated text.';
        }

        $links = $body['links'] ?? null;
        $linkList = [];
        if (is_array($links)) {
            foreach ($links as $item) {
                if (!is_string($item)) {
                    $fields['links'] = 'Links must be strings (http(s) URLs, optional "Label | URL").';
                    break;
                }
                $parsed = $this->parseLinkLine($item, $fields);
                if ($parsed === null) {
                    break;
                }
                $linkList[] = $parsed;
            }
            if (!isset($fields['links']) && count($linkList) > 5) {
                $fields['links'] = 'List at most 5 links.';
            }
        } elseif (is_string($links) && $links !== '') {
            foreach (preg_split('/\r?\n/', trim($links)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parsed = $this->parseLinkLine($line, $fields);
                if ($parsed === null) {
                    break;
                }
                $linkList[] = $parsed;
            }
        } elseif ($links !== null) {
            $fields['links'] = 'Links must be an array of strings or newline-separated text.';
        }

        $slug = $body['slug'] ?? null;
        if ($slug !== null) {
            if (!is_string($slug)) {
                $fields['slug'] = 'slug must be a string.';
            } else {
                $slug = trim(mb_strtolower($slug));
                if ($slug !== '' && (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
                    || mb_strlen($slug) > 100 || in_array($slug, self::RESERVED_SLUGS, true))) {
                    $fields['slug'] = 'slug must be 1–100 latin letters/digits separated by single dashes.';
                }
            }
        }

        if ($fields !== []) {
            throw new DomainException('validation_failed', 'The draft could not be accepted.', 422, $fields);
        }

        return [
            'name' => $name,
            'name_native' => $nameNative,
            'location' => $location,
            'headline' => $headline,
            'bio' => $bio,
            'expertise' => $expertiseList,
            'links' => $linkList,
            'slug' => $slug !== '' ? $slug : null,
        ];
    }

    /**
     * @param array<string,string> $fields
     * @return array{label:string,url:string}|null
     */
    private function parseLinkLine(string $line, array &$fields): ?array
    {
        $label = '';
        if (str_contains($line, '|')) {
            [$label, $line] = array_map('trim', explode('|', $line, 2));
            $label = mb_substr($label, 0, 80);
        }
        if (!preg_match('#^https?://[^\s<>"\']+$#iu', $line) || mb_strlen($line) > 255) {
            $fields['links'] = 'Each link must be a full http(s) URL.';
            return null;
        }
        return ['label' => $label, 'url' => $line];
    }

    /**
     * Create an unclaimed draft profile after rate limiting. The claim token
     * is returned once and stored only as a SHA-256 hash.
     *
     * @param array<string,mixed> $input validated draft data
     * @return array{row:array<string,mixed>,claim_token:string,replayed:bool}
     */
    public function createDraft(array $input, string $ip, ?string $idempotencyKey): array
    {
        $ipHash = Http::hmac($ip, $this->config);
        $hourKey = hash_hmac('sha256', 'human-hour:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $dayKey = hash_hmac('sha256', 'human-day:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $hour = $this->rateLimiter->hit($hourKey, 3600);
        $day = $this->rateLimiter->hit($dayKey, 86400);
        if ($hour > 3 || $day > 10) {
            throw new DomainException(
                'rate_limited',
                'Too many profiles were submitted from this network. Try again later.',
                429,
                [],
                ['Retry-After' => (string) 3600]
            );
        }

        // Lazy cleanup of drafts nobody claimed.
        $this->humans->purgeStaleDrafts();

        $idempotencyHash = null;
        if ($idempotencyKey !== null) {
            $idempotencyHash = hash_hmac('sha256', $ipHash . "\0" . $idempotencyKey, $this->config->string('RATE_LIMIT_SECRET'));
            $existing = $this->humans->findByIdempotencyHash($idempotencyHash);
            if ($existing !== null) {
                if ($existing['status'] !== 'awaiting_claim'
                    || $existing['name'] !== $input['name']
                    || $existing['slug'] !== ($input['slug'] ?? $existing['slug'])) {
                    throw new DomainException(
                        'idempotency_key_conflict',
                        'This idempotency key was already used with different content.',
                        409
                    );
                }
                return ['row' => $existing, 'claim_token' => '', 'replayed' => true];
            }
        }

        $publicId = bin2hex(random_bytes(16));
        $slug = $this->uniqueSlug($input['slug'] !== '' && $input['slug'] !== null
            ? $input['slug'] : $this->makeSlug((string) $input['name']), $publicId);

        $claimToken = bin2hex(random_bytes(16));
        $row = $input + [
            'slug' => $slug,
            'public_id' => $publicId,
            'claim_token_hash' => hash('sha256', $claimToken),
            'idempotency_hash' => $idempotencyHash,
        ];
        $id = $this->humans->insertDraft($row);
        $stored = $this->humans->findById($id);

        return ['row' => $stored, 'claim_token' => $claimToken, 'replayed' => false];
    }

    public function claimTokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Claim an agent-created draft: bind the chat and activate the profile.
     *
     * @return array<string,mixed>|null
     */
    public function claimByToken(string $token, string $chatId): ?array
    {
        $token = trim($token);
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $h = $this->humans->findByClaimTokenHash($this->claimTokenHash($token));
        if ($h === null) {
            return null;
        }
        $this->humans->claimTelegram((int) $h['id'], $chatId);
        return $this->humans->findById((int) $h['id']);
    }

    /* ---------------- Self-service profile management ---------------- */

    /**
     * @return array<string,mixed>|null
     */
    public function findManagedByChat(string $chatId): ?array
    {
        $h = $this->humans->findByChatId($chatId);
        if ($h === null && $chatId !== ''
            && $chatId === $this->config->string('TELEGRAM_CHAT_ID')) {
            // The platform notifications chat manages the primary answerer
            // even without an explicit binding (seeded profiles never went
            // through the pairing flow).
            $h = $this->humans->primaryAnswerer();
        }
        return $h;
    }

    /**
     * @return array<string,mixed>|null latest profile bound to the chat, any status
     */
    public function findLatestByChat(string $chatId): ?array
    {
        return $this->humans->findLatestByChatId($chatId);
    }

    /**
     * One-time magic-link edit token, valid 30 minutes; stored hashed.
     */
    public function issueEditToken(array $human): string
    {
        $token = bin2hex(random_bytes(16));
        $this->humans->setEditToken((int) $human['id'], hash('sha256', $token));
        return $token;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findHumanByEditToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        return $this->humans->findByEditTokenHash(hash('sha256', $token));
    }

    /**
     * Validate and apply the magic-link edit form. The edit token is
     * invalidated here, so one token can save exactly once.
     *
     * @param array<string,mixed> $input validated by validateApplication
     * @param list<array{url:string,title:string,description:?string}> $resources
     * @return array<string,mixed> updated row
     */
    public function applyProfileEdit(array $human, array $input, array $resources): array
    {
        $this->humans->updateProfile((int) $human['id'], $input);
        $this->humans->replaceResources((int) $human['id'], $resources);
        $this->humans->clearEditToken((int) $human['id']);
        return $this->humans->findById((int) $human['id']) ?? $human;
    }

    public function pauseQuestions(array $human): void
    {
        $this->humans->setAcceptingQuestions((int) $human['id'], false);
    }

    public function resumeQuestions(array $human): void
    {
        $this->humans->setAcceptingQuestions((int) $human['id'], true);
    }

    public function requestDelete(array $human): void
    {
        $this->humans->requestDelete((int) $human['id']);
    }

    /**
     * Two-step delete: confirm within the 30-minute window.
     */
    public function confirmDelete(array $human): bool
    {
        if (!self::deleteConfirmOpen($human['delete_confirm_at'] ?? null)) {
            return false;
        }
        $this->humans->softDelete((int) $human['id']);
        return true;
    }

    /**
     * True when a delete confirmation was requested less than 30 minutes ago.
     */
    public static function deleteConfirmOpen(?string $requestedAtUtc, ?int $now = null): bool
    {
        if ($requestedAtUtc === null || $requestedAtUtc === '') {
            return false;
        }
        $ts = strtotime($requestedAtUtc . ' UTC');
        if ($ts === false) {
            return false;
        }
        $now = $now ?? time();
        return ($now - $ts) >= 0 && ($now - $ts) <= self::DELETE_CONFIRM_SECONDS;
    }
}
