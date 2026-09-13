-- Ask a Human — example seed data (clearly fictional; replace or delete).
-- Safe to re-run: keyed on unique slugs. Requires 001_initial.sql.
-- The example human is a made-up persona: swap in real profile data (your
-- own, or data the person confirmed themselves) before going live.

INSERT INTO humans
    (slug, name, name_native, location, headline, bio, expertise_json, links_json,
     projects_json, same_as_json, status, is_active, created_at, updated_at)
VALUES
    ('ivan-petrov', 'Ivan Petrov', 'Иван Петров',
     'Moscow, Russia',
     'Software engineer and consultant',
     'Ivan Petrov is a fictional example profile that ships with Ask a Human so a fresh install has something to show. Replace this bio with a real one: a few factual sentences about who the person is, what they do, and what they can answer questions about. The same text in Russian goes after a blank line.\n\nИван Петров — вымышленный пример профиля, который поставляется вместе с Ask a Human, чтобы на свежей установке было что показать. Замените этот текст на настоящий: несколько фактических предложений о том, кто человек, чем занимается и о чём готов отвечать на вопросы.',
     '["Web development","APIs","Automation","AI agents"]',
     '[{"label":"Example Studio","url":"https://example.com"}]',
     '[{"label":"example.com","url":"https://example.com","description":"Example resource — replace with a real one"}]',
     '["https://example.com/~ivan"]',
     'approved',
     1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP();

-- One answered example question so the public pages are not empty on a
-- fresh install. Delete once real content exists.
INSERT INTO questions
    (public_id, human_id, slug, title, question, public_question, status, visibility,
     source, created_at, updated_at, notified_at)
SELECT 'e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0', h.id, 'example-question',
       'Example question',
       'What is this site and how does the question flow work?',
       'What is this site and how does the question flow work?',
       'answered', 'public', 'api', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM humans h
WHERE h.slug = 'ivan-petrov'
  AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.slug = 'example-question');

INSERT INTO answers
    (question_id, human_id, source, visible, answer, created_at, updated_at)
SELECT q.id, q.human_id, 'admin', 1,
       'This is an example answer on a fresh Ask a Human install. A real answer is written by the human in plain text and published verbatim; the question and the answer then form a public, indexable page like this one.',
       UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM questions q
WHERE q.slug = 'example-question'
  AND NOT EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id);
