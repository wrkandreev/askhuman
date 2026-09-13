<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\HumanRepository;
use App\Services\DomainException;
use App\Services\HumanService;
use App\Support\Config;
use App\Support\Http;
use App\Support\Seo;

final class JoinController
{
    public function __construct(
        private Config $config,
        private Seo $seo,
        private HumanService $service,
        private HumanRepository $humans,
        private string $basePath,
    ) {
    }

    public function form(): void
    {
        \App\render($this->basePath, 'join.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'formToken' => Http::issueFormToken($this->config),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(): void
    {
        $body = $_POST;

        $generic = 'Не удалось принять заявку. Проверьте данные и попробуйте ещё раз.';
        $honey = $body['website_url'] ?? '';
        if (is_string($honey) && trim($honey) !== '') {
            $this->renderForm(422, ['form' => $generic], $body);
            return;
        }
        $issued = Http::verifyFormToken((string) ($body['form_token'] ?? ''), $this->config);
        if ($issued === null || time() - $issued < 2) {
            $this->renderForm(422, ['form' => $generic], $body);
            return;
        }
        if (($body['public_consent'] ?? '') !== '1') {
            $this->renderForm(422, ['public_consent' => 'Подтвердите, что понимаете: профиль будет публичным.'], $body);
            return;
        }

        try {
            $input = $this->service->validateApplication(
                $body['name'] ?? null,
                $body['name_native'] ?? null,
                $body['location'] ?? null,
                $body['headline'] ?? null,
                $body['bio'] ?? null,
                $body['expertise'] ?? null,
                $body['links'] ?? null,
            );
            $row = $this->service->createApplication($input, Http::clientIp($this->config));
        } catch (DomainException $e) {
            $field = $e->fields === [] ? 'form' : array_key_first($e->fields);
            $status = $e->httpStatus === 429 ? 429 : 422;
            $message = match ($field) {
                'name' => 'Укажите имя длиной от 2 до 100 знаков.',
                'name_native' => 'Имя на другом языке не должно быть длиннее 100 знаков.',
                'location' => 'Название города и страны не должно быть длиннее 100 знаков.',
                'headline' => 'Короткое описание должно содержать от 10 до 120 знаков.',
                'bio' => 'Опишите, на что вы можете ответить, используя от 50 до 2 000 знаков.',
                'expertise' => 'Проверьте список тем: по одной теме длиной 2–60 знаков, не больше 15.',
                'links' => 'Проверьте ссылки: не больше 5 полных адресов http(s), по одному в строке.',
                default => $e->errorCode === 'rate_limited'
                    ? 'С этой сети отправлено слишком много заявок. Попробуйте позже.'
                    : $generic,
            };
            $this->renderForm($status, [$field => $message], $body);
            return;
        }

        $pairingCode = $this->service->issuePairingCode($row);
        Http::redirect('/join/' . $row['public_id'], 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function status(array $params): void
    {
        $row = $this->humans->findByPublicId($params['public_id']);
        if ($row === null || $row['status'] === 'hidden') {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        \App\render($this->basePath, 'join-status.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'h' => $row,
        ]);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $body
     */
    private function renderForm(int $status, array $errors, array $body): void
    {
        http_response_code($status);
        \App\render($this->basePath, 'join.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'formToken' => Http::issueFormToken($this->config),
            'errors' => $errors,
            'old' => $body,
        ]);
    }
}
