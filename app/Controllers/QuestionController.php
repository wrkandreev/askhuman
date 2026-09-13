<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\QuestionRepository;
use App\Services\DomainException;
use App\Services\QuestionService;
use App\Support\Config;
use App\Support\Http;
use App\Support\Seo;

final class QuestionController
{
    public function __construct(
        private Config $config,
        private Seo $seo,
        private QuestionService $service,
        private QuestionRepository $questions,
        private \App\Repositories\HumanRepository $humans,
        private string $basePath,
    ) {
    }

    /**
     * POST /questions — HTML form submission.
     */
    public function store(): void
    {
        $body = $_POST;

        // Honeypot: generic error, no detail about detection.
        $honey = $body['website_url'] ?? $body['subject_extra'] ?? '';
        if (is_string($honey) && trim($honey) !== '') {
            $this->renderForm(422, ['form' => 'Не удалось принять вопрос. Проверьте данные и попробуйте ещё раз.'], $body);
            return;
        }

        $token = (string) ($body['form_token'] ?? '');
        $issued = Http::verifyFormToken($token, $this->config);
        if ($issued === null) {
            $this->renderForm(422, ['form' => 'Не удалось принять вопрос. Проверьте данные и попробуйте ещё раз.'], $body);
            return;
        }
        if (time() - $issued < 2) {
            $this->renderForm(422, ['form' => 'Не удалось принять вопрос. Проверьте данные и попробуйте ещё раз.'], $body);
            return;
        }

        $errors = [];
        $consent = ($body['public_consent'] ?? '') === '1';
        if (!$consent) {
            $errors['public_consent'] = 'Подтвердите, что понимаете: вопрос и ответ будут публичными.';
        }

        try {
            $input = $this->service->validateInput($body['question'] ?? null, $body['context'] ?? null, null, $body['title'] ?? null);
        } catch (DomainException $e) {
            foreach ($e->fields as $field => $_message) {
                $errors[$field] = match ($field) {
                    'question' => 'Вопрос должен содержать от 20 до 4 000 знаков.',
                    'title' => 'Заголовок должен содержать от 3 до 150 знаков.',
                    'context' => 'Контекст должен быть текстом длиной не больше 8 000 знаков.',
                    default => 'Проверьте значение поля.',
                };
            }
            $input = ['question' => '', 'title' => null, 'context' => null, 'source_url' => null];
            if (is_string($body['question'] ?? null)) {
                $input['question'] = (string) $body['question'];
            }
            if (is_string($body['title'] ?? null)) {
                $input['title'] = (string) $body['title'];
            }
            if (is_string($body['context'] ?? null)) {
                $input['context'] = (string) $body['context'];
            }
        }

        if ($errors !== []) {
            $this->renderForm(422, $errors, $body);
            return;
        }

        // Target endpoint (profile forms carry the human slug); fall back to
        // the platform's primary answerer.
        $targetHuman = null;
        $targetSlug = trim((string) ($body['human_slug'] ?? ''));
        if ($targetSlug !== '') {
            $targetHuman = $this->humans->findActiveBySlug($targetSlug);
            if ($targetHuman === null) {
                $this->renderForm(422, ['form' => 'Не удалось найти выбранную страницу.'], $body);
                return;
            }
        }

        try {
            $row = $this->service->create(
                $input,
                'html',
                Http::clientIp($this->config),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['HTTP_REFERER'] ?? null,
                null,
                $targetHuman,
            );
        } catch (DomainException $e) {
            $field = $e->httpStatus === 422 ? ($e->fields === [] ? 'form' : array_key_first($e->fields)) : 'form';
            $message = $e->errorCode === 'rate_limited'
                ? 'С этой сети отправлено слишком много вопросов. Попробуйте позже.'
                : ($e->errorCode === 'duplicate_question'
                    ? 'Похоже, этот вопрос уже недавно отправляли.'
                    : ($e->errorCode === 'human_unavailable'
                        ? 'Этот человек сейчас не принимает новые вопросы. Уже опубликованные ответы остаются доступны.'
                        : 'Не удалось принять вопрос. Проверьте данные и попробуйте ещё раз.'));
            $this->renderForm($e->httpStatus === 422 ? 422 : 429, [$field => $message], $body);
            return;
        }

        Http::redirect('/q/' . rawurlencode((string) $row['slug']), 303);
    }

    /**
     * Re-render the endpoint page with form errors and preserved input.
     *
     * @param array<string,string> $body
     * @param array<string,string> $errors
     */
    private function renderForm(int $status, array $errors, array $body): void
    {
        http_response_code($status);
        $human = null;
        $slug = trim((string) ($body['human_slug'] ?? ''));
        if ($slug !== '') {
            $human = $this->humans->findActiveBySlug($slug);
        }
        if ($human === null) {
            // No endpoint context (legacy generic posts): render the catalog
            // with a generic banner so nothing is silently lost.
            \App\render($this->basePath, 'humans.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'people' => $this->humans->listActive(),
                'banner' => $errors['form'] ?? ($errors['question'] ?? 'Не удалось принять вопрос.'),
            ]);
            return;
        }
        \App\render($this->basePath, 'human.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'human' => $human,
            'resources' => $this->humans->resourcesFor((int) $human['id']),
            'latestAnswers' => $this->questions->latestAnsweredByHuman((int) $human['id'], 5),
            'formToken' => Http::issueFormToken($this->config),
            'errors' => $errors,
            'old' => $body,
            'apiPath' => '/api/humans/' . $human['slug'] . '/questions',
        ]);
    }

    /**
     * GET /q/{public_id} (tracking, 301), /q/{public_id}/{slug} (legacy, 301)
     * and /q/{slug} — the canonical Q&A page.
     *
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $row = isset($params['public_id'])
            ? $this->questions->findByPublicId($params['public_id'])
            : $this->questions->findBySlug((string) $params['slug']);
        if ($row === null || $row['status'] === 'hidden') {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }

        $canonicalPath = '/q/' . rawurlencode((string) $row['slug']);
        $requestedPath = isset($params['public_id'])
            ? '/q/' . $params['public_id'] . (isset($params['slug']) ? '/' . rawurlencode((string) $params['slug']) : '')
            : '/q/' . rawurlencode((string) $params['slug']);
        if ($requestedPath !== $canonicalPath) {
            Http::redirect($canonicalPath, 301);
            return;
        }

        $answer = $row['status'] === 'answered'
            ? $this->questions->findAnswerByQuestionId((int) $row['id'])
            : null;
        $askedTo = $this->questions->findHumanByQuestionId((int) $row['id']);

        \App\render($this->basePath, 'question.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'q' => $row,
            'answer' => $answer,
            'askedTo' => $askedTo,
            'canonicalPath' => $canonicalPath,
        ]);
    }
}
