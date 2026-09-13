<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DomainException;
use App\Services\QuestionService;
use App\Repositories\QuestionRepository;
use App\Support\Config;
use App\Support\Http;

final class ApiQuestionController
{
    private const MAX_BODY_BYTES = 32768;

    public function __construct(
        private Config $config,
        private QuestionService $service,
        private QuestionRepository $questions,
        private \App\Repositories\HumanRepository $humans,
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
        // 1. Content type
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $mime = trim(explode(';', $contentType)[0]);
        if ($mime !== 'application/json') {
            Http::apiError(415, 'unsupported_media_type', 'Send Content-Type: application/json.');
            return;
        }

        // 2. Body size before parsing
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            Http::apiError(413, 'content_too_large', 'The request body exceeds 32 KiB.');
            return;
        }

        // 3. Parse JSON
        $decoded = json_decode($raw, true, 8);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            Http::apiError(400, 'malformed_json', 'The request body is not valid JSON.');
            return;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            Http::apiError(400, 'malformed_json', 'The request body must be a JSON object.');
            return;
        }
        // Only object keys remain.
        $object = $decoded;
        if (!isset($object['public']) || $object['public'] !== true) {
            Http::apiError(422, 'validation_failed', 'The request could not be accepted.', [
                'public' => 'Confirm that you understand the question and answer will be public by sending "public": true.',
            ]);
            return;
        }
        // Explicitly reject client attempts to set protected fields.
        foreach (['status', 'id', 'answer', 'visibility', 'human_id', 'human', 'public_id'] as $forbidden) {
            if (array_key_exists($forbidden, $object)) {
                Http::apiError(422, 'validation_failed', 'The request could not be accepted.', [
                    $forbidden => 'This field cannot be set by the client.',
                ]);
                return;
            }
        }

        // 4. Idempotency-Key header
        $idempotencyKey = null;
        if (isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            $key = (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'];
            if ($key === '' || strlen($key) > 128 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
                Http::apiError(422, 'validation_failed', 'The request could not be accepted.', [
                    'Idempotency-Key' => 'The idempotency key must be 1–128 printable ASCII characters.',
                ]);
                return;
            }
            $idempotencyKey = $key;
        }

        // 5. Target human endpoint (per-human route) — must exist and be active.
        $targetHuman = null;
        if (isset($params['slug'])) {
            $targetHuman = $this->humans->findActiveBySlug($params['slug']);
            if ($targetHuman === null) {
                Http::apiError(404, 'not_found', 'No public human endpoint exists for that slug.');
                return;
            }
        }

        try {
            $input = $this->service->validateInput(
                $object['question'] ?? null,
                $object['context'] ?? null,
                $object['source_url'] ?? null,
                $object['title'] ?? null,
            );
            $row = $this->service->create(
                $input,
                'api',
                Http::clientIp($this->config),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['HTTP_REFERER'] ?? null,
                $idempotencyKey,
                $targetHuman,
            );
        } catch (DomainException $e) {
            Http::apiError(
                $e->httpStatus,
                $e->errorCode,
                $e->getMessage(),
                $e->fields === [] ? null : $e->fields,
                $e->headers
            );
            return;
        } catch (\PDOException $e) {
            // Possible concurrent idempotency unique-key race.
            if ($idempotencyKey !== null && str_contains((string) $e->getCode(), '1062')) {
                $winner = $this->service->findIdempotencyWinner(Http::clientIp($this->config), $idempotencyKey);
                if ($winner !== null) {
                    $this->emitCreated($winner, true);
                    return;
                }
            }
            throw $e;
        }

        $this->emitCreated($row, $this->service->lastCreateWasReplay);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function emitCreated(array $row, bool $replayed): void
    {
        $statusUrl = $this->config->baseUrl() . '/api/questions/' . $row['public_id'];
        $questionUrl = $this->config->baseUrl() . '/q/' . rawurlencode((string) $row['slug']);
        Http::json([
            'id' => $row['public_id'],
            'status' => $row['status'],
            'question_url' => $questionUrl,
            'status_url' => $statusUrl,
            'message' => 'Your question has been sent directly to a human. Save the status_url and check it later for the answer.',
        ], $replayed ? 200 : 201, [
            'Location' => $statusUrl,
            'Cache-Control' => 'no-store',
            'Idempotency-Replayed' => $replayed ? 'true' : 'false',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $row = $this->questions->findByPublicId($params['public_id']);
        if ($row === null || $row['status'] === 'hidden') {
            Http::apiError(404, 'not_found', 'No public question is available for that tracking ID.');
            return;
        }

        $base = $this->config->baseUrl();
        $questionUrl = $base . '/q/' . rawurlencode((string) $row['slug']);
        $askedTo = $this->questions->findHumanByQuestionId((int) $row['id']);
        $data = [
            'id' => $row['public_id'],
            'status' => $row['status'],
            'question_url' => $questionUrl,
            'created_at' => Http::isoUtc($row['created_at']),
        ];
        if (!empty($row['title'])) {
            $data['title'] = (string) $row['title'];
        }
        if ($askedTo !== null) {
            $data['asked_to'] = [
                'name' => (string) $askedTo['name'],
                'url' => $base . '/' . $askedTo['slug'],
            ];
        }
        if (!empty($row['source_url'])) {
            $data['source_url'] = (string) $row['source_url'];
        }

        if ($row['status'] === 'answered') {
            $answer = $this->questions->findAnswerByQuestionId((int) $row['id']);
            $data['question'] = (string) $row['public_question'];
            $data['context'] = $row['public_context'];
            $data['answer'] = $answer === null ? null : (string) $answer['answer'];
            $data['answered_by'] = $askedTo === null ? null : [
                'name' => (string) $askedTo['name'],
                'url' => $base . '/' . $askedTo['slug'],
            ];
            $data['answered_at'] = $answer === null ? null : Http::isoUtc($answer['created_at']);
            $data['updated_at'] = Http::isoUtc($row['updated_at']);
            $data['answered_via'] = $answer === null ? null : (string) $answer['source'];
            Http::json($data, 200, ['Cache-Control' => 'public, max-age=300']);
            return;
        }

        $data['question'] = (string) $row['question'];
        $data['context'] = $row['context'];
        $data['answer'] = null;
        $data['notified_at'] = $row['notified_at'] !== null ? Http::isoUtc($row['notified_at']) : null;
        $data['viewed_at'] = $row['first_viewed_at'] !== null ? Http::isoUtc($row['first_viewed_at']) : null;
        if ($row['status'] === 'declined') {
            $data['declined_reason'] = (string) $row['declined_reason'];
            $data['updated_at'] = Http::isoUtc($row['updated_at']);
        } else {
            $data['poll_after_seconds'] = 60;
        }
        Http::json($data, 200, ['Cache-Control' => 'no-store']);
    }
}
