<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\QuestionRepository;
use App\Services\DomainException;
use App\Services\QuestionService;
use App\Services\TelegramNotifier;
use App\Support\Config;
use App\Support\Http;

/**
 * Telegram webhook: enables answering questions directly from Telegram by
 * replying to the notification message. Security = Telegram's
 * X-Telegram-Bot-Api-Secret-Token, set via setWebhook with a value derived
 * from RATE_LIMIT_SECRET (never committed, derivable on every deploy).
 */
final class TelegramWebhookController
{
    public function __construct(
        private Config $config,
        private QuestionRepository $questions,
        private QuestionService $questionService,
        private TelegramNotifier $telegram,
        private \App\Services\HumanService $humanService,
        private \App\Services\RateLimiter $rateLimiter,
    ) {
    }

    private function expectedSecret(): string
    {
        return hash_hmac('sha256', 'telegram-webhook', $this->config->string('RATE_LIMIT_SECRET'));
    }

    public function secret(): string
    {
        return $this->expectedSecret();
    }

    public function handle(): void
    {
        $got = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!hash_equals($this->expectedSecret(), $got)) {
            http_response_code(403);
            echo 'forbidden';
            return;
        }

        $update = json_decode((string) (file_get_contents('php://input') ?: ''), true);
        // Always 200: Telegram retries non-2xx deliveries, which we do not want.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        if (!is_array($update)) {
            echo 'ok';
            return;
        }

        try {
            $this->processUpdate($update);
        } catch (\Throwable $e) {
            // Never leak internals; Telegram does not retry 2xx.
            error_log('askahuman error ' . bin2hex(random_bytes(8)) . ': webhook: ' . $e->getMessage());
        }
        echo 'ok';
    }

    /**
     * @param array<string,mixed> $update
     */
    private function processUpdate(array $update): void
    {
        $msg = $update['message'] ?? null;
        if (!is_array($msg)) {
            return; // edited_message, callback_query, etc. — not supported in MVP
        }
        $chatId = isset($msg['chat']['id']) ? (string) $msg['chat']['id'] : '';
        $text = trim((string) ($msg['text'] ?? ''));
        $replyMessageId = isset($msg['reply_to_message']['message_id'])
            ? (int) $msg['reply_to_message']['message_id'] : null;

        // Plain (non-reply) message in the notifications chat.
        if ($replyMessageId === null || $text === '' || $chatId === '') {
            if ($text !== '' && $chatId === '') {
                return;
            }
            // Claim: /start claim_<token> deep link from an agent-created draft.
            $claimToken = $this->extractClaimToken($text);
            if ($claimToken !== null) {
                $this->handleClaim($claimToken, $chatId);
                return;
            }
            // Pairing: message (or /start payload) equal to a pairing code
            // binds the chat and activates the pending profile.
            $code = $this->extractPairingCode($text);
            if ($code !== null) {
                $this->handlePairing($code, $chatId);
                return;
            }
            if ($text !== '' && $chatId !== '' && $this->dispatchCommand(strtolower($text), $chatId)) {
                return;
            }
            if ($text !== '' && $chatId !== '' && !str_starts_with($text, '/')) {
                $this->reply($chatId,
                    'Чтобы ответить на вопрос, ответьте (reply) на его уведомление своим текстом — '
                    . 'он будет опубликован как есть. «/decline причина» — отклонить вопрос.');
            }
            return;
        }

        $q = $this->questions->findByTelegramMessage($chatId, $replyMessageId);
        if ($q === null) {
            // A reply to some other bot message: without feedback the answer
            // would silently disappear, which reads as "sent" to the human.
            $this->reply($chatId,
                'Я не нашёл вопрос, на который вы отвечаете. Найдите в чате сообщение, '
                . 'начинающееся с «Новый публичный вопрос», и ответьте (reply) именно на него.');
            return;
        }
        $human = $this->questions->findHumanByQuestionId((int) $q['id']);

        // /unpublish targets an ANSWERED question, so it runs before the
        // waiting-only guard below.
        if (preg_match('/^\/unpublish\b/i', $text) === 1) {
            try {
                $this->questionService->unpublishAnswer($q);
            } catch (DomainException) {
                $this->reply($chatId, 'У этого вопроса нет опубликованного ответа — снимать нечего.');
                return;
            }
            $this->reply($chatId, 'Ответ снят с публикации. Вопрос снова ждёт ответа: ответьте (reply) на исходное уведомление, чтобы опубликовать новый.');
            return;
        }
        if ($q['status'] !== 'waiting_for_human') {
            $this->reply($chatId,
                'Этот вопрос уже обработан (статус: ' . $q['status'] . ').');
            return;
        }

        if (preg_match('/^\/decline\b/i', $text) === 1) {
            $reason = trim((string) preg_replace('/^\/decline\b\s*/i', '', $text));
            try {
                $this->questionService->decline($q, $reason !== '' ? $reason : 'Автор выбрал не отвечать на этот вопрос публично.');
            } catch (DomainException) {
                $this->reply($chatId, 'Причина должна содержать 1–1 000 знаков. Отправьте ещё раз: «/decline причина».');
                return;
            }
            $this->confirm($q, $human, 'Вопрос отклонён. Агент увидит причину; опрос прекращён.');
            return;
        }


        // Publish the reply as the answer, verbatim.
        try {
            $this->questionService->publishAnswer(
                $q,
                (string) $q['question'],
                (string) ($q['context'] ?? ''),
                $text,
                'telegram',
            );
        } catch (DomainException $e) {
            $detail = $e->fields === [] ? $e->getMessage() : (string) reset($e->fields);
            $this->reply($chatId, 'Ответ не опубликован: ' . $detail);
            return;
        }
        $this->confirm($q, $human, 'Ответ опубликован.');
    }

    /**
     * Pairing payloads: the raw 12-hex code, or /start <code> deep link.
     */
    private function extractPairingCode(string $text): ?string
    {
        if (preg_match('/^[0-9a-f]{12}$/i', $text) === 1) {
            return strtolower($text);
        }
        if (preg_match('/^\\/start(?:@\w+)?\s+([0-9a-f]{12})$/i', $text, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function handlePairing(string $code, string $chatId): void
    {
        // Brute-force protection: max 10 pairing attempts per chat per 10 min.
        $bucket = hash_hmac('sha256', 'pair:' . $chatId, $this->config->string('RATE_LIMIT_SECRET'));
        if ($this->rateLimiter->hit($bucket, 600) > 10) {
            $this->reply($chatId, 'Слишком много попыток привязки. Попробуйте позже.');
            return;
        }
        $h = $this->humanService->bindByPairingCode($code, $chatId);
        if ($h === null) {
            $this->reply($chatId, 'Код не найден или истёк. Проверьте код на странице статуса заявки.');
            return;
        }
        $endpoint = $this->config->baseUrl() . '/' . $h['slug'];
        $this->notifyOwnerOfActivation($h, 'заявка', $chatId);
        $this->reply($chatId,
            'Профиль активирован: ' . $endpoint . "\n\n" .
            'Теперь AI-агенты могут задавать вам вопросы. Уведомления будут приходить в этот чат. ' .
            'Чтобы ответить, ответьте (reply) на уведомление своим текстом — он будет опубликован. ' .
            "«/decline причина» — отклонить вопрос.\n\n" .
            'Добавьте ссылку на профиль на свой сайт или в llms.txt — как это сделать: ' .
            $this->config->baseUrl() . '/connect');
    }

    /**
     * @param array<string,mixed> $q
     * @param array<string,mixed>|null $human
     */
    private function confirm(array $q, ?array $human, string $head): void
    {
        $url = $this->config->baseUrl() . '/q/' . rawurlencode((string) $q['slug']);
        $token = (string) ($human['telegram_bot_token'] ?? '');
        $chat = (string) ($human['telegram_chat_id'] ?? '');
        if ($token === '' || $chat === '') {
            $token = $this->config->string('TELEGRAM_BOT_TOKEN');
            $chat = $this->config->string('TELEGRAM_CHAT_ID');
        }
        if ($token !== '' && $chat !== '') {
            $this->telegram->sendMessageTo($head . "\n" . 'Публичная страница: ' . $url, $token, $chat);
        }
    }

    /**
     * Reply into the same chat using the platform bot (hints, validation errors).
     */
    private function reply(string $chatId, string $text): void
    {
        $token = $this->config->string('TELEGRAM_BOT_TOKEN');
        if ($token === '' || $chatId === '') {
            return;
        }
        $this->telegram->sendMessageTo($text, $token, $chatId);
    }

    /* ---------------- Profile management commands ---------------- */

    /* ---------------- Owner moderation ---------------- */

    /**
     * @param array<string,mixed> $h
     */
    private function notifyOwnerOfActivation(array $h, string $kind, string $claimerChatId): void
    {
        $ownerChat = $this->config->string('TELEGRAM_CHAT_ID');
        if ($ownerChat === '' || $claimerChatId === $ownerChat) {
            return; // the owner activating their own profile already sees it here
        }
        $topics = implode(', ', array_slice(
            (array) (json_decode((string) ($h['expertise_json'] ?? '[]'), true) ?: []), 0, 5));
        $this->reply($ownerChat,
            "Активирован новый профиль (" . $kind . "):\n" .
            $this->config->baseUrl() . '/' . $h['slug'] . "\n" .
            "Имя: " . $h['name'] . "\n" .
            'О себе: ' . ($h['headline'] !== null && $h['headline'] !== '' ? $h['headline'] : '—') .
            ($topics !== '' ? "\nТемы: " . $topics : '') . "\n\n" .
            'Скрыть при необходимости: /hide ' . $h['slug']);
    }

    private function handleModeration(string $cmd, string $text, string $chatId): void
    {
        if ($cmd === '/hidden') {
            $rows = $this->humanService->listHidden();
            if ($rows === []) {
                $this->reply($chatId, 'Скрытых профилей нет.');
                return;
            }
            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['slug'] . ' — ' . $r['name'] . ' (вернуть: /unhide ' . $r['slug'] . ')';
            }
            $this->reply($chatId, "Скрытые профили:\n" . implode("\n", $lines));
            return;
        }

        $arg = trim((string) (explode(' ', $text, 2)[1] ?? ''));
        if ($arg === '') {
            $this->reply($chatId, 'Укажите slug: ' . $cmd . ' <slug>');
            return;
        }
        if ($cmd === '/hide') {
            $h = $this->humanService->hideBySlug($arg);
            $this->reply($chatId, $h === null
                ? 'Живой профиль «' . $arg . '» не найден (уже скрыт или не существует).'
                : 'Профиль скрыт: ' . $h['name'] . ' (' . $h['slug'] . '). Страница и каталог больше его не показывают, вопросы не принимаются. Вернуть: /unhide ' . $h['slug']);
            return;
        }
        $h = $this->humanService->unhideBySlug($arg);
        $this->reply($chatId, $h === null
            ? 'Скрытый профиль «' . $arg . '» не найден.'
            : 'Профиль возвращён: ' . $this->config->baseUrl() . '/' . $h['slug']);
    }

    /**
     * Claim payload: /start claim_<token> deep link from POST /api/humans.
     */
    public static function extractClaimToken(string $text): ?string
    {
        if (preg_match('/^\/start(?:@\w+)?\s+claim_([0-9a-f]{32})$/i', trim($text), $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function handleClaim(string $token, string $chatId): void
    {
        // Brute-force protection: max 10 claim attempts per chat per 10 min.
        $bucket = hash_hmac('sha256', 'claim:' . $chatId, $this->config->string('RATE_LIMIT_SECRET'));
        if ($this->rateLimiter->hit($bucket, 600) > 10) {
            $this->reply($chatId, 'Слишком много попыток подтверждения. Попробуйте позже.');
            return;
        }
        $h = $this->humanService->claimByToken($token, $chatId);
        if ($h === null) {
            $this->reply($chatId,
                'Профиль по этой ссылке не найден: ссылка недействительна, уже использована или истекла (48 часов). '
                . 'Попросите агента создать профиль заново.');
            return;
        }
        $endpoint = $this->config->baseUrl() . '/' . $h['slug'];
        $this->notifyOwnerOfActivation($h, 'claim', $chatId);
        $this->reply($chatId,
            'Профиль подтверждён и активирован: ' . $endpoint . "\n\n" .
            "Теперь ИИ-агенты могут задавать вам вопросы, уведомления будут приходить в этот чат.\n\n" .
            'Команды: /my — мой профиль, /edit — редактировать, /pause — пауза вопросов, ' .
            '/resume — возобновить, /delete — удалить профиль.');
    }

    /**
     * Dispatch a management command. Returns true when the text was a known
     * command (even without a bound profile) so the generic hint is skipped.
     */
    private function dispatchCommand(string $text, string $chatId): bool
    {
        $known = ['/my', '/edit', '/pause', '/resume', '/delete', '/confirm_delete', '/help', '/start'];
        $cmd = explode(' ', $text, 2)[0];
        $cmd = explode('@', $cmd, 2)[0];
        if (!in_array($cmd, $known, true)) {
            return false;
        }
        if ($cmd === '/help' || $cmd === '/start') {
            $this->reply($chatId,
                'Я — бот Ask a Human. Если у вас есть профиль, я присылаю сюда вопросы от ИИ-агентов: '
                . "ответьте (reply) на уведомление, чтобы опубликовать ответ.\n\n" .
                'Команды профиля: /my, /edit, /pause, /resume, /delete.');
            return true;
        }
        // Owner-only moderation commands live in the platform chat.
        if (in_array($cmd, ['/hide', '/unhide', '/hidden'], true)) {
            if ($chatId === '' || $chatId !== $this->config->string('TELEGRAM_CHAT_ID')) {
                return false;
            }
            $this->handleModeration($cmd, $text, $chatId);
            return true;
        }
        $h = $this->humanService->findManagedByChat($chatId);
        if ($h === null) {
            $latest = $this->humanService->findLatestByChat($chatId);
            if ($latest !== null && $latest['status'] === 'hidden') {
                $this->reply($chatId,
                    'Ваш профиль сейчас скрыт модератором. Если это ошибка — напишите владельцу сервиса.');
            } elseif ($latest !== null && $latest['status'] === 'deleted') {
                $this->reply($chatId,
                    'Ваш профиль был удалён. Опубликованные вопросы и ответы остались в открытой базе знаний. '
                    . 'Создать новый: ' . $this->config->baseUrl() . '/join');
            } else {
                $this->reply($chatId,
                    'Активный профиль, привязанный к этому чату, не найден. '
                    . 'Создайте его на ' . $this->config->baseUrl() . '/join или через своего ИИ-агента.');
            }
            return true;
        }
        match ($cmd) {
            '/my' => $this->showProfile($h, $chatId),
            '/edit' => $this->sendEditLink($h, $chatId),
            '/pause' => $this->handlePause($h, $chatId),
            '/resume' => $this->handleResume($h, $chatId),
            '/delete' => $this->handleDeleteRequest($h, $chatId),
            '/confirm_delete' => $this->handleDeleteConfirm($h, $chatId),
            default => null,
        };
        return true;
    }

    /**
     * @param array<string,mixed> $h
     */
    private function showProfile(array $h, string $chatId): void
    {
        $paused = (int) ($h['accepting_questions'] ?? 1) !== 1;
        $this->reply($chatId,
            'Ваш профиль: ' . $this->config->baseUrl() . '/' . $h['slug'] . "\n" .
            'Имя: ' . $h['name'] . "\n" .
            'Статус: ' . ($paused ? 'на паузе — новые вопросы не принимаются (/resume)' : 'активен, принимает вопросы (/pause)') . "\n\n" .
            'Команды: /edit — редактировать (ссылка на 30 минут), /pause — пауза, ' .
            '/resume — возобновить, /delete — удалить профиль.');
    }

    /**
     * @param array<string,mixed> $h
     */
    private function sendEditLink(array $h, string $chatId): void
    {
        $bucket = hash_hmac('sha256', 'edit-issue:' . $chatId, $this->config->string('RATE_LIMIT_SECRET'));
        if ($this->rateLimiter->hit($bucket, 3600) > 5) {
            $this->reply($chatId, 'Слишком много ссылок за час. Попробуйте позже.');
            return;
        }
        $token = $this->humanService->issueEditToken($h);
        $this->reply($chatId,
            "Ссылка для редактирования профиля (действует 30 минут, одноразовая):\n" .
            $this->config->baseUrl() . '/edit/' . $token . "\n\n" .
            'Никому её не пересылайте: она открывает доступ к редактированию вашего профиля.');
    }

    /**
     * @param array<string,mixed> $h
     */
    private function handlePause(array $h, string $chatId): void
    {
        if ((int) ($h['accepting_questions'] ?? 1) !== 1) {
            $this->reply($chatId, 'Вопросы уже на паузе. Отправьте /resume, чтобы возобновить.');
            return;
        }
        $this->humanService->pauseQuestions($h);
        $this->reply($chatId,
            'Новые вопросы поставлены на паузу. Ваш профиль и опубликованные ответы остаются доступны, '
            . 'агенты получают понятный ответ «человек сейчас недоступен». /resume — возобновить.');
    }

    /**
     * @param array<string,mixed> $h
     */
    private function handleResume(array $h, string $chatId): void
    {
        if ((int) ($h['accepting_questions'] ?? 1) === 1) {
            $this->reply($chatId, 'Профиль уже принимает вопросы.');
            return;
        }
        $this->humanService->resumeQuestions($h);
        $this->reply($chatId, 'Снова принимаем вопросы. Уведомления будут приходить в этот чат.');
    }

    /**
     * @param array<string,mixed> $h
     */
    private function handleDeleteRequest(array $h, string $chatId): void
    {
        $this->humanService->requestDelete($h);
        $this->reply($chatId,
            'Вы собираетесь удалить профиль «' . $h['name'] . "»." . "\n" .
            'Страница профиля станет недоступна, новые вопросы приниматься не будут. '
            . "Уже опубликованные вопросы и ответы останутся в открытой базе знаний.\n\n" .
            'Для подтверждения отправьте /confirm_delete в течение 30 минут.');
    }

    /**
     * @param array<string,mixed> $h
     */
    private function handleDeleteConfirm(array $h, string $chatId): void
    {
        if (!$this->humanService->confirmDelete($h)) {
            $this->reply($chatId, 'Подтверждение не найдено или устарело. Сначала отправьте /delete, затем /confirm_delete.');
            return;
        }
        $this->reply($chatId,
            'Профиль удалён. Публичная страница больше не доступна, новые вопросы не принимаются. '
            . 'Опубликованные вопросы и ответы остались в открытой базе знаний.');
    }
}
