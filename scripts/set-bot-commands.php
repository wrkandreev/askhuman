<?php

declare(strict_types=1);

/**
 * Register the Telegram command menu once per deployment:
 *   php scripts/set-bot-commands.php
 * Reads config/local.php (never committed). No secrets are printed.
 */

require __DIR__ . '/../app/bootstrap.php';

global $config;

$base = rtrim($config->string('TELEGRAM_API_BASE'), '/');
if ($base === '') {
    $base = 'https://api.telegram.org';
}
$key = hash_hmac('sha256', 'telegram-relay', $config->string('RATE_LIMIT_SECRET'));

$commands = [
    ['command' => 'my', 'description' => 'Мой профиль: статус и действия'],
    ['command' => 'edit', 'description' => 'Редактировать профиль (ссылка на 30 минут)'],
    ['command' => 'pause', 'description' => 'Приостановить приём новых вопросов'],
    ['command' => 'resume', 'description' => 'Возобновить приём вопросов'],
    ['command' => 'delete', 'description' => 'Удалить профиль (с подтверждением)'],
    ['command' => 'help', 'description' => 'Как работает бот'],
];

function callSetMyCommands(string $url, string $key, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Relay-Key: ' . $key,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [(int) $code, substr((string) $body, 0, 120)];
}

$ch = curl_init($base . '/bot' . $config->string('TELEGRAM_BOT_TOKEN') . '/setMyCommands');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['commands' => $commands], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Relay-Key: ' . $key,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT => 10,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo 'default scope: http ', $code, ': ', substr((string) $body, 0, 120), PHP_EOL;
$ok = $code === 200;

// Extended moderation menu, visible only in the platform (owner) chat.
$ownerChat = $config->string('TELEGRAM_CHAT_ID');
if ($ownerChat !== '') {
    $ownerCommands = array_merge($commands, [
        ['command' => 'hidden', 'description' => 'Скрытые профили (модерация)'],
        ['command' => 'hide', 'description' => 'Скрыть профиль: /hide <slug>'],
        ['command' => 'unhide', 'description' => 'Вернуть профиль: /unhide <slug>'],
    ]);
    [$code2, $body2] = callSetMyCommands(
        $base . '/bot' . $config->string('TELEGRAM_BOT_TOKEN') . '/setMyCommands',
        $key,
        ['commands' => $ownerCommands, 'scope' => ['type' => 'chat', 'chat_id' => (int) $ownerChat]]
    );
    echo 'owner scope: http ', $code2, ': ', $body2, PHP_EOL;
    $ok = $ok && $code2 === 200;
}
exit($ok ? 0 : 1);
