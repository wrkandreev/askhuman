<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

/**
 * Best-effort synchronous Telegram notification. Failures are logged
 * (sanitized) and never propagated to the caller's response.
 */
final class TelegramNotifier
{
    public function __construct(private Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->string('TELEGRAM_BOT_TOKEN') !== ''
            && $this->config->string('TELEGRAM_CHAT_ID') !== '';
    }

    /**
     * @param array<string,mixed> $question
     * @param array<string,mixed>|null $human target human row; when the human
     *        has own Telegram credentials, deliver to their chat instead of
     *        the platform default.
     * @return array{message_id:int,chat_id:string}|null delivery metadata
     *         when Telegram accepted the message (ok:true), else null.
     */
    public function notifyNewQuestion(array $question, ?array $human = null): ?array
    {
        $token = $this->config->string('TELEGRAM_BOT_TOKEN');
        $chatId = $this->config->string('TELEGRAM_CHAT_ID');
        if ($human !== null && !empty($human['telegram_bot_token']) && !empty($human['telegram_chat_id'])) {
            $token = (string) $human['telegram_bot_token'];
            $chatId = (string) $human['telegram_chat_id'];
        }
        if ($token === '' || $chatId === '') {
            error_log('askahuman telegram: not configured; question ' . $question['public_id'] . ' stored without notification');
            return null;
        }

        $url = $this->config->baseUrl() . '/admin/questions/' . (int) $question['id'];
        $text = "Новый публичный вопрос\n\n"
            . ($human !== null ? 'Кому: ' . $human['name'] . "\n\n" : '')
            . (!empty($question['title']) ? 'Заголовок: ' . $question['title'] . "\n\n" : '')
            . "Вопрос:\n" . $this->preview((string) $question['question'], 600) . "\n\n"
            . "Контекст:\n" . ($question['context'] !== null && $question['context'] !== ''
                ? $this->preview((string) $question['context'], 400)
                : 'Не указан') . "\n\n"
            . 'Страница-источник: ' . (!empty($question['source_url'])
                ? $this->preview((string) $question['source_url'], 300)
                : 'Не указана') . "\n\n"
            . 'Tracking ID: ' . $question['public_id'] . "\n"
            . 'Канал: ' . $question['source'] . "\n\n"
            . "Редактировать в админке:\n" . $url . "\n\n"
            . 'Или просто ответьте (reply) на это сообщение — текст будет опубликован как есть. '
            . '«/decline причина» — отклонить вопрос.';

        return $this->send($text, $token, $chatId);
    }

    /**
     * Fire-and-forget message to a specific chat (webhook confirmations).
     */
    public function sendMessageTo(string $text, string $token, string $chatId): void
    {
        $this->send($text, $token, $chatId);
    }

    /**
     * @param array<string,mixed> $human pending application row
     */
    public function notifyNewApplication(array $human): void
    {
        if (!$this->isConfigured()) {
            error_log('askahuman telegram: not configured; application ' . $human['public_id'] . ' stored without notification');
            return;
        }

        $url = $this->config->baseUrl() . '/admin/humans/' . (int) $human['id'];
        $text = "Новая заявка на human endpoint\n\n"
            . 'Имя: ' . $human['name']
            . ($human['name_native'] !== null && $human['name_native'] !== '' ? ' (' . $human['name_native'] . ')' : '') . "\n"
            . 'О себе: ' . $human['headline'] . "\n"
            . 'Локация: ' . ($human['location'] !== null && $human['location'] !== '' ? $human['location'] : 'Не указана') . "\n\n"
            . "Описание:\n" . $this->preview((string) $human['bio'], 500) . "\n\n"
            . "Просмотр в админке:\n" . $url;

        $this->send($text);
    }

    private function preview(string $value, int $maxChars): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
        if (mb_strlen($value) > $maxChars) {
            $value = rtrim(mb_substr($value, 0, $maxChars)) . '…';
        }
        return $value;
    }

    /**
     * @return array{message_id:int,chat_id:string}|null decoded sendMessage
     *         result when Telegram accepted the message.
     */
    private function send(string $text, ?string $token = null, ?string $chatId = null): ?array
    {
        $token = $token ?? $this->config->string('TELEGRAM_BOT_TOKEN');
        $chatId = $chatId ?? $this->config->string('TELEGRAM_CHAT_ID');
        $base = rtrim($this->config->string('TELEGRAM_API_BASE'), '/');
        $customRelay = $base !== '' && $base !== 'https://api.telegram.org';
        if ($base === '') {
            $base = 'https://api.telegram.org';
        }
        $endpoint = $base . '/bot' . rawurlencode($token) . '/sendMessage';
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ]);

        try {
            if (function_exists('curl_init')) {
                return $this->sendWithCurl($endpoint, $payload, $chatId, $customRelay);
            }
            return $this->sendWithStreams($endpoint, $payload, $chatId);
        } catch (\Throwable $e) {
            // Never log the token or full endpoint.
            error_log('askahuman telegram: send failed (' . get_class($e) . ')');
        }
        return null;
    }

    /**
     * @return array{message_id:int,chat_id:string}|null
     */
    private function sendWithCurl(string $endpoint, string $payload, string $chatId, bool $customRelay = false): ?array
    {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return null;
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
        ];
        if ($customRelay) {
            // Custom base points at our own relay (self-signed certificate);
            // direct api.telegram.org keeps full verification. The relay
            // requires a shared derived key header. The extra TLS hop through
            // the relay plus Telegram's own round trip needs more headroom
            // than the direct-call budgets below.
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_HTTPHEADER] = ['X-Relay-Key: ' . hash_hmac('sha256', 'telegram-relay', $this->config->string('RATE_LIMIT_SECRET'))];
            $options[CURLOPT_CONNECTTIMEOUT] = 5;
            $options[CURLOPT_TIMEOUT] = 10;
        } else {
            $options[CURLOPT_CONNECTTIMEOUT] = 2;
            $options[CURLOPT_TIMEOUT] = 4;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($body === false || $curlErr !== '') {
            error_log('askahuman telegram: curl failure: ' . $curlErr);
            return null;
        }
        if ($status >= 400) {
            error_log('askahuman telegram: api returned http ' . $status);
            return null;
        }
        if (!is_string($body)) {
            error_log('askahuman telegram: api returned non-string body');
            return null;
        }
        $decoded = json_decode($body, true);
        $messageId = $decoded['result']['message_id'] ?? null;
        if (($decoded['ok'] ?? false) !== true || !is_int($messageId)) {
            error_log('askahuman telegram: api returned ok=false or missing message_id');
            return null;
        }
        return ['message_id' => $messageId, 'chat_id' => $chatId];
    }

    private function sendWithStreams(string $endpoint, string $payload, string $chatId): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($endpoint, false, $context);
        if ($body === false) {
            error_log('askahuman telegram: stream send failed');
            return null;
        }
        $decoded = json_decode($body, true);
        $messageId = $decoded['result']['message_id'] ?? null;
        if (($decoded['ok'] ?? false) !== true || !is_int($messageId)) {
            return null;
        }
        return ['message_id' => $messageId, 'chat_id' => $chatId];
    }
}
