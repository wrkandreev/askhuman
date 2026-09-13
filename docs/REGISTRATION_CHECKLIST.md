# Agent-Discovery Registration Checklist

Короткий рабочий список. Обоснования, точные требования и 29 источников — в
[полном исследовании](AGENT_DISCOVERY_RESEARCH.md).

## P0 — до регистрации

- [ ] Исправить JSON-LD опубликованного ответа: `QAPage`, корректный автор и даты.
- [ ] Добавить публичные Terms и Support/Contact URL.
- [ ] В OpenAPI указать абсолютный server URL `https://askhuman.ru`.
- [ ] Реализовать remote MCP `https://askhuman.ru/mcp` на Streamable HTTP.
- [ ] Экспортировать два tools: `ask_question` и `get_answer`.
- [ ] Для `ask_question` точно описать публичность и внешнее действие; поставить
  `readOnlyHint: false`, `destructiveHint: true`, `openWorldHint: true`.
- [ ] Для `get_answer` поставить `readOnlyHint: true`,
  `destructiveHint: false`, `openWorldHint: false`.
- [ ] Протестировать rate limits, idempotency, moderation и аварийное отключение
  write-tool.
- [ ] Добавить MCP-ссылку в `/for-agents` и `/llms.txt`.
- [ ] Добавить `/.well-known/apis.json`.
- [ ] Проверить пропуск registry scanners и поисковых ботов на уровне CDN/WAF.
- [ ] Опубликовать 5–10 настоящих качественных ответов.

## P1 — канонические каналы

- [ ] [Official MCP Registry](https://modelcontextprotocol.io/registry/quickstart):
  `mcp-publisher init` → `validate` → domain/GitHub login → `publish`.
- [ ] [OpenAI Plugins Directory](https://developers.openai.com/plugins/deploy/submission):
  Platform → Create plugin → With MCP → Universal → Scan Tools → review → publish.
- [ ] [Google Search Console](https://search.google.com/search-console): Domain
  property, DNS verification, sitemap, URL Inspection.
- [ ] [Bing Webmaster Tools](https://www.bing.com/webmasters/): verify/import,
  sitemap, IndexNow для опубликованных/изменённых/удалённых ответов.
- [ ] [Яндекс Вебмастер](https://webmaster.yandex.com/): verify, sitemap,
  диагностика индексирования.

## P2 — дополнительные каталоги

- [ ] [Smithery](https://smithery.ai/new).
- [ ] Проверить автоматическое появление в [Glama](https://glama.ai/mcp/servers),
  затем claim/add только при необходимости.
- [ ] [APIs.io](https://apis.io/add/).
- [ ] [Postman Public API Network](https://learning.postman.com/docs/postman-api-network/showcase/publish/public-apis/).

## P3 — вторичная дистрибуция

- [ ] Публичный GitHub repository и topics `mcp-server`, `ai-agents`,
  `human-in-the-loop`, `ask-a-human`.
- [ ] Remote-only awesome MCP list; в основной
  `punkpeye/awesome-mcp-servers` идти только с self-hostable public repository.
- [ ] Бесплатная submission на [mcpservers.org](https://mcpservers.org/submit).
- [ ] PulseMCP и cursor.directory — только после перепроверки актуальной формы.
- [ ] `mcp.so` — только как измеримый платный эксперимент.
- [ ] Show HN / MCP communities / Habr / dev.to — содержательные разные публикации,
  не массовый одинаковый анонс.

## Не делать

- Не искать отдельную обязательную «GitHub MCP Registry» submission: GitHub OAuth
  — способ авторизации официального Registry.
- Не добавлять отдельные crawler-секции в `robots.txt`, пока `User-agent: *`
  разрешает сайт; отдельно проверять WAF.
- Не считать `Google-Extended` поисковым crawler user-agent.
- Не считать `llms.txt` регистрацией или заменой MCP/search indexing.
- Не строить legacy `ai-plugin.json` и A2A Agent Card для текущего продукта.
- Не платить вторичным каталогам без attribution и конверсии.

## Контроль результата

- [ ] Registry/plugin cards опубликованы и открываются.
- [ ] Golden prompts: 5 direct, 5 indirect, 5 negative, 3 safety.
- [ ] Полный тест submit → сохранить ID → delayed `get_answer`.
- [ ] Метрики разделены на discovery, activation, completion и quality.
- [ ] Главный KPI: полезный завершённый цикл «вопрос → человеческий ответ → ответ
  получен агентом», а не количество листингов.
