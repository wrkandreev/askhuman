<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\HumanRepository;
use App\Repositories\QuestionRepository;
use App\Support\Config;
use App\Support\Translit;
use App\Support\Database;
use App\Support\Http;
use PDO;
use RuntimeException;

/**
 * Domain errors carry an HTTP-ish signal and field messages.
 */
final class DomainException extends RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}

final class QuestionService
{
    /**
     * True when create() returned an already-stored idempotent replay.
     */
    public bool $lastCreateWasReplay = false;

    public function __construct(
        private PDO $pdo,
        private QuestionRepository $questions,
        private HumanRepository $humans,
        private RateLimiter $rateLimiter,
        private TelegramNotifier $telegram,
        private Config $config,
    ) {
    }

    /**
     * Validate and normalize question input shared by HTML and API.
     *
     * @return array{question:string,title:?string,context:?string,source_url:?string}
     */
    public function validateInput(mixed $question, mixed $context, mixed $sourceUrl = null, mixed $title = null): array
    {
        $fields = [];
        if (!is_string($question)) {
            $fields['question'] = 'Enter a question containing at least 20 characters.';
        } else {
            $question = trim($question);
            $len = mb_strlen($question);
            if ($len < 20) {
                $fields['question'] = 'Enter a question containing at least 20 characters.';
            } elseif ($len > 4000) {
                $fields['question'] = 'Question must not exceed 4,000 characters.';
            } elseif (Http::cleanUtf8($question) === null) {
                $fields['question'] = 'The question contains invalid text encoding.';
            }
        }

        if ($title !== null) {
            if (!is_string($title)) {
                $fields['title'] = 'title must be a string of 3–150 characters.';
            } else {
                $title = trim($title);
                if ($title === '') {
                    $title = null;
                } elseif (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
                    $fields['title'] = 'title must be 3–150 characters.';
                } elseif (Http::cleanUtf8($title) === null) {
                    $fields['title'] = 'The title contains invalid text encoding.';
                }
            }
        }

        if ($context !== null) {
            if (!is_string($context)) {
                $fields['context'] = 'Context must be text.';
            } else {
                $context = trim($context);
                if (mb_strlen($context) > 8000) {
                    $fields['context'] = 'Context must not exceed 8,000 characters.';
                } elseif (Http::cleanUtf8($context) === null) {
                    $fields['context'] = 'The context contains invalid text encoding.';
                } elseif ($context === '') {
                    $context = null;
                }
            }
        }

        if ($sourceUrl !== null) {
            if (!is_string($sourceUrl)) {
                $fields['source_url'] = 'source_url must be a string containing a http(s) URL.';
            } else {
                $sourceUrl = trim($sourceUrl);
                if ($sourceUrl === '') {
                    $sourceUrl = null;
                } elseif (mb_strlen($sourceUrl) > 2048
                    || preg_match('#^https?://[^\s<>"\']+$#iu', $sourceUrl) !== 1) {
                    $fields['source_url'] = 'source_url must be a full http(s) URL up to 2,048 characters.';
                }
            }
        }

        if ($fields !== []) {
            throw new DomainException('validation_failed', 'The request could not be accepted.', 422, $fields);
        }

        $question = trim(Http::cleanUtf8((string) $question));
        $context = $context === null ? null : trim(Http::cleanUtf8((string) $context));
        $title = $title === null ? null : trim(Http::cleanUtf8((string) $title));

        return ['question' => $question, 'title' => $title === '' ? null : $title,
            'context' => $context === '' ? null : $context,
            'source_url' => $sourceUrl];
    }

    /**
     * Cosmetic slug from the first useful words of the question.
     */
    public function makeSlug(string $question): string
    {
        $slug = trim($question);
        // Take first words up to ~80 chars, then trim to <=100 total.
        if (mb_strlen($slug) > 100) {
            $slug = mb_substr($slug, 0, 100);
        }
        // Transliterate so URLs stay readable ASCII (no percent-encoding).
        $slug = Translit::toLatin($slug);
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_strtolower($slug);
        if ($slug === '') {
            return 'question';
        }
        // A bare 32-hex slug would be shadowed by the /q/{public_id} tracking
        // route, which is matched first.
        if (preg_match('/^[0-9a-f]{32}$/', $slug) === 1) {
            return $slug . '-q';
        }
        return $slug;
    }

    /**
     * Question slugs double as canonical URL segments (/q/{slug}), so they
     * must be unique across questions.
     */
    private function uniqueSlug(string $base): string
    {
        if (!$this->questions->slugExists($base)) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            $candidate = $base . '-' . $i;
            if (!$this->questions->slugExists($candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * Create a question. Assumes input was validated via validateInput().
     *
     * @param array{question:string,title:?string,context:?string,source_url:?string} $input
     * @param array<string,mixed>|null $targetHuman approved human row (endpoint target);
     *        null falls back to the platform's primary answerer.
     * @return array<string,mixed> the stored question row
     */
    public function create(
        array $input,
        string $source,
        string $ip,
        ?string $userAgent,
        ?string $referrer,
        ?string $idempotencyKey,
        ?array $targetHuman = null,
    ): array {
        $ipHash = Http::hmac($ip, $this->config);
        $this->lastCreateWasReplay = false;

        $idempotencyHash = null;
        $fingerprint = null;
        if ($idempotencyKey !== null) {
            $idempotencyHash = hash_hmac('sha256', $ipHash . "\0" . $idempotencyKey, $this->config->string('RATE_LIMIT_SECRET'));
            $fingerprint = hash('sha256', json_encode(
                [$input['question'], $input['title'] ?? null, $input['context'], $input['source_url'] ?? null, $targetHuman['slug'] ?? null, true],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            // Idempotency first: a legitimate replay must succeed even past the limit.
            $existing = $this->findByHash($idempotencyHash);
            if ($existing !== null) {
                if ($existing['idempotency_fingerprint'] !== $fingerprint) {
                    throw new DomainException(
                        'idempotency_key_conflict',
                        'This idempotency key was already used with different content.',
                        409
                    );
                }
                $this->lastCreateWasReplay = true;
                return $existing;
            }
        }

        // Creation limits: count this attempt in both fixed windows atomically.
        $hourKey = hash_hmac('sha256', 'create-hour:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $dayKey = hash_hmac('sha256', 'create-day:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        $hourAttempts = $this->rateLimiter->hit($hourKey, 3600);
        $dayAttempts = $this->rateLimiter->hit($dayKey, 86400);

        if ($hourAttempts > 5 || $dayAttempts > 20) {
            $retry = $hourAttempts > 5 ? 3600 : 86400;
            throw new DomainException(
                'rate_limited',
                'Too many questions were submitted from this network. Try again later.',
                429,
                [],
                ['Retry-After' => (string) $retry]
            );
        }

        // Duplicate normalized content from the same IP within 10 minutes.
        $normalizedHash = hash('sha256', mb_strtolower(trim($input['question'])));
        foreach ($this->questions->recentByIpHash($ipHash, 600) as $recent) {
            if (hash('sha256', mb_strtolower(trim((string) $recent['question']))) === $normalizedHash) {
                throw new DomainException(
                    'duplicate_question',
                    'This question appears to have been submitted recently.',
                    409
                );
            }
        }

        $human = $targetHuman ?? $this->humans->primaryAnswerer();
        if ($human === null) {
            throw new DomainException('internal_error', 'The request could not be processed.', 500);
        }
        if ((int) ($human['accepting_questions'] ?? 1) !== 1) {
            throw new DomainException(
                'human_unavailable',
                'This human is not accepting new questions right now.',
                409
            );
        }

        $publicId = bin2hex(random_bytes(16));
        $slug = $this->uniqueSlug($this->makeSlug($input['title'] ?? $input['question']));
        $now = Database::now();
        $ua = $userAgent === null ? null : substr(Http::cleanUtf8($userAgent) ?? '', 0, 512);
        $ref = $referrer === null ? null : substr(Http::cleanUtf8($referrer) ?? '', 0, 2048);

        $stmt = $this->pdo->prepare(
            'INSERT INTO questions
                (public_id, human_id, slug, title, question, context, source_url, status, visibility, source,
                 user_agent, referrer, ip_hash, idempotency_key_hash, idempotency_fingerprint,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $publicId,
            $human['id'],
            $slug,
            $input['title'] ?? null,
            $input['question'],
            $input['context'],
            $input['source_url'] ?? null,
            'waiting_for_human',
            'public',
            $source === 'api' ? 'api' : 'html',
            $ua,
            $ref,
            $ipHash,
            $idempotencyHash,
            $fingerprint,
            $now,
            $now,
        ]);

        $row = $this->questions->findByPublicId($publicId);

        // Notify after commit; Telegram failure must never affect the response.
        try {
            $delivery = $this->telegram->notifyNewQuestion($row, $human);
            if ($delivery !== null) {
                $meta = $this->pdo->prepare(
                    'UPDATE questions SET notified_at = UTC_TIMESTAMP(),
                        telegram_message_id = ?, telegram_chat_id = ? WHERE id = ?'
                );
                $meta->execute([$delivery['message_id'], $delivery['chat_id'], $row['id']]);
            }
        } catch (\Throwable $e) {
            error_log('askahuman telegram: notification error for ' . $publicId . ': ' . $e->getMessage());
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByHash(string $idempotencyHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM questions WHERE idempotency_key_hash = ?');
        $stmt->execute([$idempotencyHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Handle a unique-key race: return the winning row for a replay.
     *
     * @return array<string,mixed>|null
     */
    public function findIdempotencyWinner(string $ip, string $idempotencyKey): ?array
    {
        $ipHash = Http::hmac($ip, $this->config);
        $hash = hash_hmac('sha256', $ipHash . "\0" . $idempotencyKey, $this->config->string('RATE_LIMIT_SECRET'));
        return $this->findByHash($hash);
    }

    /**
     * Publish or update an answer transactionally.
     *
     * @param string $via 'admin' or 'telegram' (answer origin, metrics only)
     */
    public function publishAnswer(
        array $question,
        string $publicQuestion,
        string $publicContext,
        string $answer,
        string $via = 'admin',
    ): void {
        $publicQuestion = trim($publicQuestion);
        $publicContext = trim($publicContext);
        $answer = trim($answer);
        if ($publicQuestion === '' || mb_strlen($publicQuestion) > 4000) {
            throw new DomainException('validation_failed', 'The request could not be accepted.', 422, [
                'public_question' => 'Public question is required (up to 4,000 characters).',
            ]);
        }
        if (mb_strlen($publicContext) > 8000) {
            throw new DomainException('validation_failed', 'The request could not be accepted.', 422, [
                'public_context' => 'Context must not exceed 8,000 characters.',
            ]);
        }
        if ($answer === '' || mb_strlen($answer) > 20000) {
            throw new DomainException('validation_failed', 'The request could not be accepted.', 422, [
                'answer' => 'Answer is required (1–20,000 characters).',
            ]);
        }

        $now = Database::now();
        $this->pdo->beginTransaction();
        try {
            $existing = $this->questions->findAnswerByQuestionId((int) $question['id']);
            if ($existing === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO answers (question_id, human_id, visible, answer, source, created_at, updated_at)
                     VALUES (?, ?, 1, ?, ?, ?, ?)'
                );
                $stmt->execute([$question['id'], $question['human_id'], $answer, $via === 'telegram' ? 'telegram' : 'admin', $now, $now]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE answers SET answer = ?, visible = 1, source = ?, updated_at = ? WHERE question_id = ?'
                );
                $stmt->execute([$answer, $via === 'telegram' ? 'telegram' : 'admin', $now, $question['id']]);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE questions
                 SET public_question = ?, public_context = ?, declined_reason = NULL,
                     status = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $publicQuestion,
                $publicContext === '' ? null : $publicContext,
                'answered',
                $now,
                $question['id'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Unpublish the answer (keep the row): the question returns to the
     * waiting state and can be re-answered later.
     */
    public function unpublishAnswer(array $question): void
    {
        if ($question['status'] !== 'answered') {
            throw new DomainException('validation_failed', 'There is no published answer for this question.', 422);
        }
        $now = Database::now();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE answers SET visible = 0, updated_at = ? WHERE question_id = ?');
            $stmt->execute([$now, $question['id']]);
            $stmt = $this->pdo->prepare(
                "UPDATE questions SET status = 'waiting_for_human', updated_at = ? WHERE id = ?"
            );
            $stmt->execute([$now, $question['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function decline(array $question, string $reason): void
    {        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('validation_failed', 'The request could not be accepted.', 422, [
                'declined_reason' => 'A public reason is required (1–1,000 characters).',
            ]);
        }
        $now = Database::now();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE questions SET status = ?, declined_reason = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute(['declined', $reason, $now, $question['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hide(array $question): void
    {
        $now = Database::now();
        $stmt = $this->pdo->prepare('UPDATE questions SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute(['hidden', $now, $question['id']]);
    }
}
