<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\HumanRepository;
use App\Services\DomainException;
use App\Services\HumanService;
use App\Services\RateLimiter;
use App\Support\Config;
use App\Support\Http;
use App\Support\Seo;

/**
 * /edit/{token} — one-time magic-link profile edit form issued from Telegram
 * (/edit). No accounts: the single-use 30-minute token, bound to one profile
 * and stored hashed, is the credential.
 */
final class EditProfileController
{
    public function __construct(
        private Config $config,
        private Seo $seo,
        private HumanService $humanService,
        private HumanRepository $humans,
        private RateLimiter $rateLimiter,
        private string $basePath,
    ) {
    }

    /**
     * @param array<string,string> $params
     */
    public function form(array $params): void
    {
        $human = $this->humanService->findHumanByEditToken((string) $params['token']);
        if ($human === null) {
            if (!$this->limitProbes()) {
                http_response_code(429);
                return;
            }
            $this->render('invalid');
            return;
        }
        $this->render('form', $human, [
            'formToken' => Http::issueFormToken($this->config),
            'errors' => [],
            'old' => $this->prefill($human),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function save(array $params): void
    {
        $token = (string) $params['token'];
        $human = $this->humanService->findHumanByEditToken($token);
        $body = $_POST;

        if ($human === null || Http::verifyFormToken((string) ($body['form_token'] ?? ''), $this->config) === null) {
            if (!$this->limitProbes()) {
                http_response_code(429);
                return;
            }
            $this->render('invalid');
            return;
        }
        $honey = $body['website_url'] ?? '';
        if (is_string($honey) && trim($honey) !== '') {
            $this->render('invalid');
            return;
        }

        try {
            $input = $this->humanService->validateApplication(
                $body['name'] ?? null,
                $body['name_native'] ?? null,
                $body['location'] ?? null,
                $body['headline'] ?? null,
                $body['bio'] ?? null,
                $body['expertise'] ?? null,
                $body['links'] ?? null,
            );
        } catch (DomainException $e) {
            http_response_code(422);
            $this->render('form', $human, [
                'formToken' => Http::issueFormToken($this->config),
                'errors' => $e->fields,
                'old' => $body,
            ]);
            return;
        }

        $resources = $this->humanService->parseResourcesForEdit($body['resources'] ?? null);
        $updated = $this->humanService->applyProfileEdit($human, $input, $resources);

        $this->render('done', $updated);
    }

    /**
     * Slow down brute-forcing of edit tokens. Returns false when the limit
     * is exhausted; failures stay generic either way.
     */
    private function limitProbes(): bool
    {
        $ipHash = Http::hmac(Http::clientIp($this->config), $this->config);
        $bucket = hash_hmac('sha256', 'edit-probe:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));
        return $this->rateLimiter->hit($bucket, 3600) <= 10;
    }

    /**
     * Current profile values as textarea-friendly lines.
     *
     * @param array<string,mixed> $human
     * @return array<string,string>
     */
    private function prefill(array $human): array
    {
        $lines = static fn (mixed $json): string => implode("\n", array_map(
            static fn (array $r): string => trim(($r['label'] ?? $r['title'] ?? '') !== ''
                ? (($r['label'] ?? $r['title'] ?? '') . ' | ' . $r['url'])
                : (string) $r['url']),
            (array) (json_decode((string) $json, true) ?: [])
        ));
        $resourceLines = implode("\n", array_map(
            static fn (array $r): string => trim(($r['title'] ?? '') . ' | ' . $r['url']
                . (isset($r['description']) && $r['description'] !== null ? ' | ' . $r['description'] : ''), ' |'),
            $this->humans->resourcesFor((int) $human['id'])
        ));
        return [
            'name' => (string) $human['name'],
            'name_native' => (string) ($human['name_native'] ?? ''),
            'location' => (string) ($human['location'] ?? ''),
            'headline' => (string) ($human['headline'] ?? ''),
            'bio' => (string) ($human['bio'] ?? ''),
            'expertise' => implode("\n", (array) (json_decode((string) ($human['expertise_json'] ?? '[]'), true) ?: [])),
            'links' => $lines($human['links_json'] ?? '[]'),
            'resources' => $resourceLines,
        ];
    }

    /**
     * @param array<string,mixed>|null $human
     * @param array{formToken?:string,errors?:array<string,string>,old?:array<string,string>} $view
     */
    private function render(string $mode, ?array $human = null, array $view = []): void
    {
        $robots = 'noindex,nofollow';
        $base = $this->config->baseUrl();
        \App\render($this->basePath, 'edit-profile.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'mode' => $mode,
            'human' => $human,
            'formToken' => $view['formToken'] ?? '',
            'errors' => $view['errors'] ?? [],
            'old' => $view['old'] ?? [],
            'robots' => $robots,
            'base' => $base,
        ]);
    }
}
