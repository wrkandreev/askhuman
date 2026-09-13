-- Ask a Human — initial schema (run once on an empty database).
-- This single file creates the complete current schema; there are no other
-- migrations. MySQL 5.7+ compatible; no JSON operators, generated columns,
-- or enums.

CREATE TABLE humans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    name VARCHAR(190) NOT NULL,
    name_native VARCHAR(190) NULL,
    location VARCHAR(190) NULL,
    headline VARCHAR(255) NULL,
    bio TEXT NULL,
    expertise_json TEXT NULL,
    links_json TEXT NULL,
    projects_json TEXT NULL,
    same_as_json TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'approved',
    review_note TEXT NULL,
    pairing_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pairing_created_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    accepting_questions TINYINT(1) NOT NULL DEFAULT 1,
    claim_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claim_created_at DATETIME NULL,
    edit_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    edit_token_created_at DATETIME NULL,
    delete_confirm_at DATETIME NULL,
    idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    telegram_bot_token VARCHAR(256) NULL,
    telegram_chat_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_humans_slug (slug),
    UNIQUE KEY uq_humans_idempotency (idempotency_hash),
    UNIQUE KEY uq_humans_public_id (public_id),
    KEY idx_humans_active (is_active),
    KEY idx_humans_status (status),
    KEY idx_humans_pairing (pairing_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    human_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(190) NULL,
    question TEXT NOT NULL,
    context TEXT NULL,
    public_question TEXT NULL,
    public_context TEXT NULL,
    status VARCHAR(32) NOT NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'public',
    source VARCHAR(16) NOT NULL,
    source_url VARCHAR(2048) NULL,
    user_agent VARCHAR(512) NULL,
    referrer VARCHAR(2048) NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    declined_reason TEXT NULL,
    notified_at DATETIME NULL,
    first_viewed_at DATETIME NULL,
    telegram_message_id BIGINT NULL,
    telegram_chat_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_questions_public_id (public_id),
    UNIQUE KEY uq_questions_slug (slug),
    UNIQUE KEY uq_questions_idempotency (idempotency_key_hash),
    KEY idx_questions_status_created (status, created_at),
    KEY idx_questions_human_created (human_id, created_at),
    KEY idx_questions_ip_created (ip_hash, created_at),
    KEY idx_questions_tg (telegram_chat_id, telegram_message_id),
    CONSTRAINT fk_questions_human
        FOREIGN KEY (human_id) REFERENCES humans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    human_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'admin',
    visible TINYINT(1) NOT NULL DEFAULT 1,
    answer MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answers_question (question_id),
    KEY idx_answers_human_created (human_id, created_at),
    CONSTRAINT fk_answers_question
        FOREIGN KEY (question_id) REFERENCES questions(id),
    CONSTRAINT fk_answers_human
        FOREIGN KEY (human_id) REFERENCES humans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public sites/projects a human is the owner, author, or subject-matter
-- expert of. Separate entity on purpose: one human may connect several
-- sites later.
CREATE TABLE human_resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    human_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(190) NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_human_resources_human (human_id, is_active),
    CONSTRAINT fk_human_resources_human
        FOREIGN KEY (human_id) REFERENCES humans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
