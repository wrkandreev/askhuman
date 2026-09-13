<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\HumanRepository;
use App\Repositories\QuestionRepository;
use App\Services\DomainException;
use App\Services\QuestionService;
use App\Services\RateLimiter;
use App\Support\Config;
use App\Support\Http;
use App\Support\Seo;

final class AdminController
{
    private bool $sessionStarted = false;

    public function __construct(
        private Config $config,
        private Seo $seo,
        private QuestionRepository $questions,
        private HumanRepository $humans,
        private RateLimiter $rateLimiter,
        private \App\Services\QuestionService $questionService,
        private \App\Services\HumanService $humanService,
        private string $basePath,
    ) {
    }

    private function startSession(): void
    {
        if ($this->sessionStarted) {
            return;
        }
        $secure = $this->config->string('APP_ENV') === 'production' ? true : false;
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('askahuman_admin');
        session_start();
        $this->sessionStarted = true;
    }

    private function isAuthenticated(): bool
    {
        $this->startSession();
        return ($_SESSION['authenticated'] ?? false) === true;
    }

    private function csrfToken(): string
    {
        $this->startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    private function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            Http::redirect('/admin/login', 303);
            exit;
        }
    }

    private function checkCsrf(): bool
    {
        $token = (string) ($_POST['csrf_token'] ?? '');
        return $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    public function loginForm(): void
    {
        if ($this->isAuthenticated()) {
            Http::redirect('/admin', 303);
            return;
        }
        \App\render($this->basePath, 'admin/login.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'error' => null,
        ]);
    }

    public function login(): void
    {
        $this->startSession();
        $ipHash = Http::hmac(Http::clientIp($this->config), $this->config);
        $bucketKey = hash_hmac('sha256', 'login:' . $ipHash, $this->config->string('RATE_LIMIT_SECRET'));

        $attempts = $this->rateLimiter->hit($bucketKey, 900);
        if ($attempts > 10) {
            http_response_code(429);
            \App\render($this->basePath, 'admin/login.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'error' => 'Too many login attempts. Try again later.',
            ]);
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $hash = $this->config->string('ADMIN_PASSWORD_HASH');
        $ok = $hash !== '' && $password !== '' && password_verify($password, $hash);

        if (!$ok) {
            http_response_code(422);
            \App\render($this->basePath, 'admin/login.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'error' => 'The password is incorrect.',
            ]);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->rateLimiter->reset($bucketKey);
        Http::redirect('/admin', 303);
    }

    public function logout(): void
    {
        $this->startSession();
        if (!$this->checkCsrf()) {
            http_response_code(422);
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invalid request</title></head>'
                . '<body><h1>Invalid request</h1><p>The request could not be completed.</p></body></html>';
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        Http::redirect('/admin/login', 303);
    }

    public function dashboard(): void
    {
        $this->requireAuth();
        $filter = match ($_GET['status'] ?? '') {
            'waiting' => 'waiting_for_human',
            'answered' => 'answered',
            'declined' => 'declined',
            'hidden' => 'hidden',
            default => null,
        };
        \App\render($this->basePath, 'admin/dashboard.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'counts' => $this->questions->counts(),
            'median' => $this->questions->medianResponseSeconds(),
            'items' => $this->questions->adminList($filter),
            'filter' => $filter,
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function review(array $params): void
    {
        $this->requireAuth();
        $q = $this->questions->findById((int) $params['id']);
        if ($q === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        // First authenticated look = "seen by the human" signal for agents.
        if ($q['first_viewed_at'] === null) {
            $stmt = $this->questions->markFirstViewed((int) $q['id']);
            if ($stmt) {
                $q['first_viewed_at'] = gmdate('Y-m-d H:i:s');
            }
        }
        $answer = $this->questions->findAnswerByQuestionId((int) $q['id']);
        \App\render($this->basePath, 'admin/review.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'q' => $q,
            'answer' => $answer,
            'csrfToken' => $this->csrfToken(),
            'error' => null,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function answer(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        $q = $this->questions->findById((int) $params['id']);
        if ($q === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $service = $this->questionService;
        try {
            $service->publishAnswer(
                $q,
                (string) ($_POST['public_question'] ?? ''),
                (string) ($_POST['public_context'] ?? ''),
                (string) ($_POST['answer'] ?? ''),
            );
        } catch (DomainException $e) {
            $answer = $this->questions->findAnswerByQuestionId((int) $q['id']);
            http_response_code($e->httpStatus);
            \App\render($this->basePath, 'admin/review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'q' => $q,
                'answer' => $answer,
                'csrfToken' => $this->csrfToken(),
                'error' => implode(' ', $e->fields === [] ? [$e->getMessage()] : array_values($e->fields)),
            ]);
            return;
        }
        Http::redirect('/admin/questions/' . (int) $q['id'], 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function decline(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        $q = $this->questions->findById((int) $params['id']);
        if ($q === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $service = $this->questionService;
        try {
            $service->decline($q, (string) ($_POST['declined_reason'] ?? ''));
        } catch (DomainException $e) {
            $answer = $this->questions->findAnswerByQuestionId((int) $q['id']);
            http_response_code($e->httpStatus);
            \App\render($this->basePath, 'admin/review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'q' => $q,
                'answer' => $answer,
                'csrfToken' => $this->csrfToken(),
                'error' => implode(' ', $e->fields === [] ? [$e->getMessage()] : array_values($e->fields)),
            ]);
            return;
        }
        Http::redirect('/admin/questions/' . (int) $q['id'], 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function hide(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        if (($_POST['confirm_hide'] ?? '') !== '1') {
            http_response_code(422);
            $q = $this->questions->findById((int) $params['id']);
            $answer = $q === null ? null : $this->questions->findAnswerByQuestionId((int) $q['id']);
            \App\render($this->basePath, 'admin/review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'q' => $q ?? [],
                'answer' => $answer,
                'csrfToken' => $this->csrfToken(),
                'error' => 'Confirm the hide action with the checkbox.',
            ]);
            return;
        }
        $q = $this->questions->findById((int) $params['id']);
        if ($q === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $this->questionService->hide($q);
        Http::redirect('/admin', 303);
    }

    /* ---------------- Human catalog administration ---------------- */

    public function humansList(): void
    {
        $this->requireAuth();
        \App\render($this->basePath, 'admin/humans.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'items' => $this->humans->adminList(),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function humanReview(array $params): void
    {
        $this->requireAuth();
        $h = $this->humans->findById((int) $params['id']);
        if ($h === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        \App\render($this->basePath, 'admin/human-review.php', [
            'config' => $this->config,
            'seo' => $this->seo,
            'h' => $h,
            'csrfToken' => $this->csrfToken(),
            'error' => null,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function humanUpdate(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        $h = $this->humans->findById((int) $params['id']);
        if ($h === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        try {
            $data = $this->humanService->validateProfileUpdate($_POST);
            $this->humans->updateFields((int) $h['id'], $data);
            $this->humans->replaceResources((int) $h['id'], $data['resources']);
            $this->humans->setTelegram((int) $h['id'], $data['telegram_bot_token'], $data['telegram_chat_id']);
        } catch (DomainException $e) {
            http_response_code($e->httpStatus);
            \App\render($this->basePath, 'admin/human-review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'h' => $h,
                'csrfToken' => $this->csrfToken(),
                'error' => implode(' ', $e->fields === [] ? [$e->getMessage()] : array_values($e->fields)),
            ]);
            return;
        }
        Http::redirect('/admin/humans/' . (int) $h['id'], 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function humanApprove(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        $h = $this->humans->findById((int) $params['id']);
        if ($h === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $this->humanService->approve((int) $h['id']);
        Http::redirect('/admin/humans/' . (int) $h['id'], 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function humanDecline(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        $h = $this->humans->findById((int) $params['id']);
        if ($h === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        try {
            $this->humanService->decline((int) $h['id'], (string) ($_POST['review_note'] ?? ''));
        } catch (DomainException $e) {
            http_response_code($e->httpStatus);
            \App\render($this->basePath, 'admin/human-review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'h' => $h,
                'csrfToken' => $this->csrfToken(),
                'error' => implode(' ', $e->fields === [] ? [$e->getMessage()] : array_values($e->fields)),
            ]);
            return;
        }
        Http::redirect('/admin/humans', 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function humanHide(array $params): void
    {
        $this->requireAuth();
        if (!$this->checkCsrf()) {
            $this->renderCsrfError();
            return;
        }
        if (($_POST['confirm_hide'] ?? '') !== '1') {
            http_response_code(422);
            $h = $this->humans->findById((int) $params['id']);
            \App\render($this->basePath, 'admin/human-review.php', [
                'config' => $this->config,
                'seo' => $this->seo,
                'h' => $h ?? [],
                'csrfToken' => $this->csrfToken(),
                'error' => 'Confirm the hide action with the checkbox.',
            ]);
            return;
        }
        $h = $this->humans->findById((int) $params['id']);
        if ($h === null) {
            http_response_code(404);
            include $this->basePath . '/templates/errors/404.php';
            return;
        }
        $this->humanService->hide((int) $h['id']);
        Http::redirect('/admin/humans', 303);
    }

    private function renderCsrfError(): void
    {
        http_response_code(422);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invalid request</title></head>'
            . '<body><h1>Invalid request</h1><p>The request could not be completed.</p>'
            . '<p><a href="/admin">Back to admin</a></p></body></html>';
    }
}
