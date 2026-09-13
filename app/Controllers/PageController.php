<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\HumanRepository;
use App\Repositories\QuestionRepository;
use App\Support\Config;
use App\Support\Http;
use App\Support\Seo;

final class PageController
{
    public function __construct(
        private Config $config,
        private Seo $seo,
        private HumanRepository $humans,
        private QuestionRepository $questions,
        private string $basePath,
    ) {
    }

    public function home(): void
    {
        $example = $this->humans->primaryAnswerer();
        \App\render($this->basePath, 'home.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'example' => $example,
            'exampleResources' => $example === null ? [] : $this->humans->resourcesFor((int) $example['id']),
            'latest' => $this->questions->latestAnswered(10),
        ]);
    }

    /**
     * Onboarding for website owners: how to expose the personal endpoint.
     */
    public function connect(): void
    {
        $example = $this->humans->primaryAnswerer();
        \App\render($this->basePath, 'connect.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'example' => $example,
        ]);
    }

    /**
     * Legacy profile URL /humans/{slug} → canonical endpoint /{slug}.
     *
     * @param array<string,string> $params
     */
    public function human(array $params): void
    {
        $human = $this->humans->findActiveBySlug($params['slug']);
        if ($human === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        Http::redirect('/' . $human['slug'], 301);
    }

    /**
     * The human endpoint page: /{slug} (registered last, catch-all).
     *
     * @param array<string,string> $params
     */
    public function humanEndpoint(array $params): void
    {
        $human = $this->humans->findActiveBySlug($params['slug']);
        if ($human === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        \App\render($this->basePath, 'human.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'human' => $human,
            'resources' => $this->humans->resourcesFor((int) $human['id']),
            'latestAnswers' => $this->questions->latestAnsweredByHuman((int) $human['id'], 5),
            'formToken' => Http::issueFormToken($this->config),
            'errors' => [],
            'old' => [],
            'apiPath' => '/api/humans/' . $human['slug'] . '/questions',
        ]);
    }

    public function archive(): void
    {
        $page = 1;
        if (isset($_GET['page'])) {
            if (!ctype_digit((string) $_GET['page']) || (int) $_GET['page'] < 1) {
                $page = 0; // invalid → redirect to canonical archive
            } else {
                $page = (int) $_GET['page'];
            }
            if ($page === 1) {
                Http::redirect('/questions', 301);
                return;
            }
            if ($page === 0) {
                Http::redirect('/questions', 301);
                return;
            }
        }

        $perPage = 20;
        $total = $this->questions->countAnswered();
        $maxPage = max(1, (int) ceil($total / $perPage));
        if ($page > $maxPage && $total > 0) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $items = $this->questions->latestAnswered($perPage, ($page - 1) * $perPage);

        \App\render($this->basePath, 'questions.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'items' => $items,
            'page' => $page,
            'maxPage' => $maxPage,
            'total' => $total,
        ]);
    }

    /**
     * Catalog of active human answerers.
     */
    public function humans(): void
    {
        \App\render($this->basePath, 'humans.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'people' => $this->humans->listActive(),
        ]);
    }

    public function forAgents(): void
    {
        $example = $this->humans->primaryAnswerer();
        \App\render($this->basePath, 'for-agents.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'exampleSlug' => $example === null ? 'ivan-petrov' : (string) $example['slug'],
        ]);
    }

    public function privacy(): void
    {
        \App\render($this->basePath, 'privacy.php', [
            'config' => $this->config,
            'seo' => $this->seo,
        ]);
    }

    public function openapi(): void
    {
        header('Content-Type: application/yaml; charset=utf-8');
        readfile($this->basePath . '/openapi.yaml');
    }

    public function llmsTxt(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $base = $this->config->baseUrl();
        $endpoints = '';
        foreach ($this->humans->listActive() as $human) {
            $slug = rawurlencode((string) $human['slug']);
            $name = (string) $human['name'];
            $paused = (int) ($human['accepting_questions'] ?? 1) !== 1;
            $endpoints .= "- {$name} (human endpoint): {$base}/{$slug}" . ($paused ? " — not accepting new questions right now\n" : "\n");
            $endpoints .= "  POST {$base}/api/humans/{$slug}/questions — ask {$name} directly\n";
        }
        echo <<<TXT
        # {$this->config->string('APP_NAME')}

        Ask a Human (askhuman.ru) gives website owners and experts a public "human endpoint": if an AI agent studies their website and cannot find the information it needs, the agent can ask the human behind that website directly.

        Who answers: real people who connected their endpoint here. Each endpoint page names the human, their topics, and the sites or projects they stand behind.

        Warning: questions and answers are public and may be indexed by search engines and AI systems. Do not submit secrets or personal data.

        How to ask:
        1. POST a JSON question to the human's endpoint ({"title": "...", "question": "...", "context": "...", "source_url": "...", "public": true}); title (3-150 chars) is an optional short heading that becomes the answer page h1 and SEO title; source_url is the page where the information was missing.
        2. Save the returned status_url.
        3. Poll it no more than once every 60 seconds, increasing the interval gradually up to 15 minutes.
        4. Stop when the status is "answered" (read "answer") or "declined" (read "declined_reason").
        5. The question and the human answer become a public, indexable page at question_url.

        Links:
        - Platform home: {$base}/
        - Instructions for AI agents: {$base}/for-agents
        - OpenAPI 3.1 specification: {$base}/openapi.yaml
        - Catalog of human endpoints: {$base}/humans
        - Public answers archive: {$base}/questions

        Generic API (asks the platform's primary answerer):
        - POST {$base}/api/questions — JSON, "public": true required
        - GET {$base}/api/questions/{id} — poll question status

        Human endpoints:

        {$endpoints}
        TXT;
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /telegram\n";
        echo "\n";
        echo 'Sitemap: ' . $this->config->baseUrl() . "/sitemap.xml\n";
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = $this->config->baseUrl();
        $entries = [
            ['loc' => $base . '/', 'lastmod' => null],
            ['loc' => $base . '/for-agents', 'lastmod' => null],
            ['loc' => $base . '/connect', 'lastmod' => null],
            ['loc' => $base . '/humans', 'lastmod' => null],
            ['loc' => $base . '/join', 'lastmod' => null],
            ['loc' => $base . '/privacy', 'lastmod' => null],
            ['loc' => $base . '/questions', 'lastmod' => null],
        ];
        foreach ($this->humans->listActive() as $human) {
            $entries[] = [
                'loc' => $base . '/' . rawurlencode((string) $human['slug']),
                'lastmod' => Http::isoUtc($human['updated_at']),
            ];
        }
        foreach ($this->questions->answeredForSitemap() as $row) {
            $entries[] = [
                'loc' => $base . '/q/' . rawurlencode((string) $row['slug']),
                'lastmod' => Http::isoUtc($row['lastmod']),
            ];
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            echo '  <url><loc>' . $this->seo->xmlEscape($entry['loc']) . '</loc>';
            if ($entry['lastmod'] !== null) {
                echo '<lastmod>' . $this->seo->xmlEscape($entry['lastmod']) . '</lastmod>';
            }
            echo "</url>\n";
        }
        echo '</urlset>' . "\n";
    }
}
