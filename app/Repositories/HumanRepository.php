<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class HumanRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM humans WHERE slug = ? AND is_active = 1 AND status = "approved"');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * First approved human by seniority; the platform's default addressee
     * for the general /api/questions route.
     *
     * @return array<string,mixed>|null
     */
    public function primaryAnswerer(): ?array
    {
        return $this->listActive()[0] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        return $this->pdo->query(
            'SELECT * FROM humans WHERE is_active = 1 AND status = "approved" ORDER BY created_at ASC'
        )->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM humans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $publicId) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM humans WHERE public_id = ?');
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Any row with this slug (for slug uniqueness on application).
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM humans WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Admin list: pending first (oldest first), then other statuses.
     *
     * @return list<array<string,mixed>>
     */
    public function adminList(): array
    {
        return $this->pdo->query(
            'SELECT id, slug, public_id, name, headline, location, status, created_at
             FROM humans
             ORDER BY FIELD(status, "pending", "approved", "declined", "hidden"), created_at ASC
             LIMIT 200'
        )->fetchAll();
    }

    /**
     * Moderation: hidden profiles, most recently hidden first.
     *
     * @return list<array<string,mixed>>
     */
    public function listHidden(): array
    {
        return $this->pdo->query(
            'SELECT id, slug, name, headline, updated_at FROM humans
             WHERE status = "hidden" ORDER BY updated_at DESC LIMIT 50'
        )->fetchAll();
    }

    /* ---------------- Resources (sites the human stands behind) ---------------- */

    /**
     * @return list<array<string,mixed>>
     */
    public function resourcesFor(int $humanId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, url, title, description FROM human_resources
             WHERE human_id = ? AND is_active = 1 ORDER BY id ASC'
        );
        $stmt->execute([$humanId]);
        return $stmt->fetchAll();
    }

    /**
     * Replace the full resource list for a human (admin edit).
     *
     * @param list<array{url:string,title:string,description:?string}> $resources
     */
    public function replaceResources(int $humanId, array $resources): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM human_resources WHERE human_id = ?');
            $stmt->execute([$humanId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO human_resources (human_id, url, title, description, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            foreach ($resources as $r) {
                $insert->execute([$humanId, $r['url'], $r['title'], $r['description'], $now, $now]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setTelegram(int $id, ?string $botToken, ?string $chatId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET telegram_bot_token = ?, telegram_chat_id = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$botToken, $chatId, gmdate('Y-m-d H:i:s'), $id]);
    }

    /* ---------------- Telegram pairing (self-serve onboarding) ---------------- */

    public function setPairingCode(int $id, string $code): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET pairing_code = ?, pairing_created_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$code, $id]);
    }

    /**
     * A pending application by its one-time pairing code (unexpired).
     *
     * @return array<string,mixed>|null
     */
    public function findByPairingCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM humans
             WHERE pairing_code = ? AND status = 'pending' AND is_active = 0
               AND pairing_created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
             LIMIT 1"
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Bind the chat and activate the profile (self-serve approval).
     */
    public function bindTelegram(int $id, string $chatId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE humans SET telegram_chat_id = ?, telegram_bot_token = NULL,
                status = 'approved', is_active = 1, pairing_code = NULL,
                pairing_created_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        );
        $stmt->execute([$chatId, $id]);
    }

    /**
     * @param array<string,mixed> $h validated application data
     */
    public function insertApplication(array $h): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO humans
                (slug, public_id, name, name_native, location, headline, bio,
                 expertise_json, links_json, projects_json, status, review_note,
                 is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "[]", "pending", NULL, 0, ?, ?)'
        );
        $stmt->execute([
            $h['slug'],
            $h['public_id'],
            $h['name'],
            $h['name_native'],
            $h['location'],
            $h['headline'],
            $h['bio'],
            json_encode($h['expertise'], JSON_UNESCAPED_UNICODE),
            json_encode($h['links'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $h normalized editable fields
     */
    public function updateFields(int $id, array $h): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET name = ?, name_native = ?, location = ?, headline = ?,
                bio = ?, expertise_json = ?, links_json = ?, projects_json = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $h['name'],
            $h['name_native'],
            $h['location'],
            $h['headline'],
            $h['bio'],
            json_encode($h['expertise'], JSON_UNESCAPED_UNICODE),
            json_encode($h['links'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($h['projects'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function setStatus(int $id, string $status, bool $active, ?string $reviewNote = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET status = ?, is_active = ?, review_note = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$status, $active ? 1 : 0, $reviewNote, gmdate('Y-m-d H:i:s'), $id]);
    }

    /* ---------------- Expert lifecycle (pause/edit/claim/delete) ---------------- */

    /**
     * Latest profile bound to this chat, any status (for /my and hints).
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByChatId(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM humans WHERE telegram_chat_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Active approved profile bound to this chat (management commands).
     *
     * @return array<string,mixed>|null
     */
    public function findByChatId(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM humans
             WHERE telegram_chat_id = ? AND status = "approved" AND is_active = 1
             ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Draft awaiting claim by its one-time token hash (unexpired, 48 h).
     *
     * @return array<string,mixed>|null
     */
    public function findByClaimTokenHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM humans
             WHERE claim_token_hash = ? AND status = 'awaiting_claim'
               AND claim_created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Bind the claiming chat and activate the agent-created draft.
     */
    public function claimTelegram(int $id, string $chatId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE humans SET telegram_chat_id = ?, telegram_bot_token = NULL,
                status = 'approved', is_active = 1, accepting_questions = 1,
                claim_token_hash = NULL, claim_created_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        );
        $stmt->execute([$chatId, $id]);
    }

    public function setAcceptingQuestions(int $id, bool $accepting): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET accepting_questions = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$accepting ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function setEditToken(int $id, ?string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET edit_token_hash = ?, edit_token_created_at = ?,
                updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$hash, $hash !== null ? gmdate('Y-m-d H:i:s') : null,
            gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * Approved active profile by its one-time edit token hash (unexpired).
     *
     * @return array<string,mixed>|null
     */
    public function findByEditTokenHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM humans
             WHERE edit_token_hash = ? AND status = 'approved' AND is_active = 1
               AND edit_token_created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function clearEditToken(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET edit_token_hash = NULL, edit_token_created_at = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function requestDelete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET delete_confirm_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Soft delete: keep questions/answers intact, hide the profile.
     */
    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE humans SET status = 'deleted', is_active = 0, accepting_questions = 0,
                delete_confirm_at = NULL, pairing_code = NULL, pairing_created_at = NULL,
                claim_token_hash = NULL, claim_created_at = NULL,
                edit_token_hash = NULL, edit_token_created_at = NULL,
                updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    /**
     * Agent-created draft profile (not public until claimed).
     *
     * @param array<string,mixed> $h validated draft data with claim_token_hash
     */
    public function insertDraft(array $h): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO humans
                (slug, public_id, name, name_native, location, headline, bio,
                 expertise_json, links_json, projects_json, status, review_note,
                 is_active, accepting_questions, claim_token_hash, claim_created_at,
                 idempotency_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', 'awaiting_claim', NULL,
                 0, 0, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $h['slug'],
            $h['public_id'],
            $h['name'],
            $h['name_native'],
            $h['location'],
            $h['headline'],
            $h['bio'],
            json_encode($h['expertise'], JSON_UNESCAPED_UNICODE),
            json_encode($h['links'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $h['claim_token_hash'],
            $now,
            $h['idempotency_hash'],
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM humans WHERE idempotency_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lazy cleanup: drafts never claimed within 7 days.
     */
    public function purgeStaleDrafts(): void
    {
        $this->pdo->exec(
            "DELETE FROM humans WHERE status = 'awaiting_claim'
             AND claim_created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
    }

    /**
     * Profile edit without touching admin-managed fields (projects, creds).
     *
     * @param array<string,mixed> $h normalized editable fields
     */
    public function updateProfile(int $id, array $h): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE humans SET name = ?, name_native = ?, location = ?, headline = ?,
                bio = ?, expertise_json = ?, links_json = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $h['name'],
            $h['name_native'],
            $h['location'],
            $h['headline'],
            $h['bio'],
            json_encode($h['expertise'], JSON_UNESCAPED_UNICODE),
            json_encode($h['links'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }
}
