<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class QuestionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $publicId) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM questions WHERE public_id = ?');
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM questions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM questions WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM questions WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() !== false;
    }

    /**
     * @return array<string,mixed>|null answer row joined by question id
     */
    public function findAnswerByQuestionId(int $questionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM answers WHERE question_id = ?');
        $stmt->execute([$questionId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * The human a question was asked to.
     *
     * @return array<string,mixed>|null
     */
    public function findHumanByQuestionId(int $questionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.* FROM humans h JOIN questions q ON q.human_id = h.id WHERE q.id = ?'
        );
        $stmt->execute([$questionId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Mark the first authenticated admin view; true when this call set it.
     */
    public function markFirstViewed(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE questions SET first_viewed_at = UTC_TIMESTAMP()
             WHERE id = ? AND first_viewed_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Question by Telegram notification message (for webhook reply matching).
     *
     * @return array<string,mixed>|null
     */
    public function findByTelegramMessage(string $chatId, int $messageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM questions WHERE telegram_chat_id = ? AND telegram_message_id = ?'
        );
        $stmt->execute([$chatId, $messageId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Latest answered questions asked to one human (endpoint page).
     *
     * @return list<array<string,mixed>>
     */
    public function latestAnsweredByHuman(int $humanId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.public_id, q.slug, q.title, q.public_question, q.source_url, a.created_at AS answered_at
             FROM questions q JOIN answers a ON a.question_id = q.id
             WHERE q.status = ? AND q.human_id = ?
             ORDER BY a.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, 'answered', PDO::PARAM_STR);
        $stmt->bindValue(2, $humanId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function latestAnswered(int $limit, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.public_id, q.slug, q.title, q.public_question, q.created_at, q.updated_at,
                    a.created_at AS answered_at
             FROM questions q JOIN answers a ON a.question_id = q.id
             WHERE q.status = ?
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, 'answered', PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAnswered(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM questions WHERE status = ?');
        $stmt->execute(['answered']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * All answered rows for the sitemap (MVP scale).
     *
     * @return list<array<string,mixed>>
     */
    public function answeredForSitemap(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.public_id, q.slug, GREATEST(q.updated_at, a.updated_at) AS lastmod
             FROM questions q JOIN answers a ON a.question_id = q.id
             WHERE q.status = ?'
        );
        $stmt->execute(['answered']);
        return $stmt->fetchAll();
    }

    /**
     * Recent rows from one IP hash for the duplicate-content window.
     *
     * @return list<array<string,mixed>>
     */
    public function recentByIpHash(string $ipHash, int $seconds): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT question, created_at FROM questions
             WHERE ip_hash = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
        );
        $stmt->bindValue(1, $ipHash, PDO::PARAM_STR);
        $stmt->bindValue(2, $seconds, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Admin list with optional status filter, waiting first then newest.
     *
     * @return list<array<string,mixed>>
     */
    public function adminList(?string $status): array
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, public_id, slug, question, status, source, created_at
                 FROM questions WHERE status = ? ORDER BY created_at DESC LIMIT 200'
            );
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT id, public_id, slug, question, status, source, created_at
                 FROM questions
                 ORDER BY FIELD(status, "waiting_for_human", "answered", "declined", "hidden"),
                          created_at DESC
                 LIMIT 200'
            );
        }
        return $stmt->fetchAll();
    }

    /**
     * @return array{total:int,waiting:int,answered:int,declined:int,hidden:int}
     */
    public function counts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS c FROM questions GROUP BY status')->fetchAll();
        $counts = ['total' => 0, 'waiting' => 0, 'answered' => 0, 'declined' => 0, 'hidden' => 0];
        foreach ($rows as $row) {
            $counts['total'] += (int) $row['c'];
            match ($row['status']) {
                'waiting_for_human' => $counts['waiting'] += (int) $row['c'],
                'answered' => $counts['answered'] += (int) $row['c'],
                'declined' => $counts['declined'] += (int) $row['c'],
                'hidden' => $counts['hidden'] += (int) $row['c'],
                default => null,
            };
        }
        return $counts;
    }

    /**
     * Median seconds from question creation to first answer, computed in PHP.
     */
    public function medianResponseSeconds(): ?float
    {
        $rows = $this->pdo->query(
            'SELECT a.created_at AS answered_at, q.created_at AS asked_at
             FROM answers a JOIN questions q ON q.id = a.question_id'
        )->fetchAll();
        if ($rows === []) {
            return null;
        }
        $durations = [];
        foreach ($rows as $row) {
            $durations[] = strtotime($row['answered_at'] . ' UTC') - strtotime($row['asked_at'] . ' UTC');
        }
        sort($durations);
        $n = count($durations);
        return $n % 2 === 1
            ? (float) $durations[(int) (($n - 1) / 2)]
            : ($durations[$n / 2 - 1] + $durations[$n / 2]) / 2;
    }
}
