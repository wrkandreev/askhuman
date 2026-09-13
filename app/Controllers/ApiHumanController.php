<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DomainException;
use App\Services\HumanService;
use App\Support\Config;
use App\Support\Http;

/**
 * POST /api/humans — public API for AI agents to prepare a draft profile on
 * behalf of a human. Drafts cannot accept questions until the human claims
 * them via the Telegram deep link (claim_url).
 */
final class ApiHumanController
{
    private const MAX_BODY_BYTES = 32768;

    public function __construct(
        private Config $config,
        private HumanService $service,
        private string $basePath,
    ) {
    }

    /**
     * @param array<string,string> $params
     */
    public function options(array $params): void
    {
        http_response_code(204);
        Http::apiCorsHeaders();
    }

    /**
     * @param array<string,string> $params
     */
    public function store(array $params): void
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $mime = trim(explode(';', $contentType)[0]);
        if ($mime !== 'application/json') {
            Http::apiError(415, 'unsupported_media_type', 'Send Content-Type: application/json.');
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            Http::apiError(413, 'content_too_large', 'The request body exceeds 32 KiB.');
            return;
        }

        $decoded = json_decode($raw, true, 8);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            Http::apiError(400, 'malformed_json', 'The request body is not valid JSON.');
            return;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            Http::apiError(400, 'malformed_json', 'The request body must be a JSON object.');
            return;
        }
        foreach (['status', 'id', 'public_id', 'is_active', 'accepting_questions'] as $forbidden) {
            if (array_key_exists($forbidden, $decoded)) {
                Http::apiError(422, 'validation_failed', 'The draft could not be accepted.', [
                    $forbidden => 'This field cannot be set by the client.',
                ]);
                return;
            }
        }

        $idempotencyKey = null;
        if (isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            $key = (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'];
            if ($key === '' || strlen($key) > 128 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
                Http::apiError(422, 'validation_failed', 'The draft could not be accepted.', [
                    'Idempotency-Key' => 'The idempotency key must be 1–128 printable ASCII characters.',
                ]);
                return;
            }
            $idempotencyKey = $key;
        }

        try {
            $input = $this->service->validateDraftInput($decoded);
            $result = $this->service->createDraft($input, Http::clientIp($this->config), $idempotencyKey);
        } catch (DomainException $e) {
            Http::apiError(
                $e->httpStatus,
                $e->errorCode,
                $e->getMessage(),
                $e->fields === [] ? null : $e->fields,
                $e->headers
            );
            return;
        }

        $row = $result['row'];
        $base = $this->config->baseUrl();
        $profileUrl = $base . '/' . rawurlencode((string) $row['slug']);
        $claimUrl = $result['replayed']
            ? null
            : 'https://t.me/' . rawurlencode($this->config->string('TELEGRAM_BOT_USERNAME'))
                . '?start=claim_' . $result['claim_token'];

        Http::json([
            'id' => $row['public_id'],
            'slug' => $row['slug'],
            'status' => $row['status'],
            'profile_url' => $profileUrl,
            'claim_url' => $claimUrl,
            'message' => $result['replayed']
                ? 'This idempotency key was already used. The stored draft is returned; the previous claim_url stays valid until it expires or is claimed.'
                : 'Draft created. It cannot accept questions until the human claims it: share the claim_url so they can confirm ownership in Telegram by pressing Start.',
        ], $result['replayed'] ? 200 : 201, [
            'Location' => $profileUrl,
            'Cache-Control' => 'no-store',
        ]);
    }
}
