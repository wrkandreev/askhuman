# Как сделать Ask a Human заметным для AI-агентов

Дата исследования: 12 сентября 2026 года.

Объект исследования: кодовая база `askhuman.ru` / **Ask a Human**. Работающий
production намеренно не проверялся: на момент исследования шёл другой деплой.

## Краткий вывод

Универсальной формы, в которой можно «зарегистрировать сайт для всех агентов»,
нет. У сервиса есть три разных вида обнаружения, и каждый требует своего
канала:

1. **Страница находится и цитируется** — это обычные поисковые индексы,
   AI-search-краулеры, sitemap и ссылки с других сайтов.
2. **Действие можно подключить как инструмент** — это удалённый MCP-сервер и
   каталоги MCP/плагинов.
3. **Агент уже открыл страницу и видит доступные действия** — это WebMCP (site
   tools), который не заменяет ни поиск, ни установку MCP.

Для Ask a Human наибольший эффект даст не ещё один SEO-файл, а тонкий удалённый
MCP-слой над уже существующим REST API. После этого приоритетный путь такой:

1. запустить стабильный `https://askhuman.ru/mcp` на Streamable HTTP;
2. опубликовать его в официальном MCP Registry;
3. независимо отправить MCP-only plugin на проверку в OpenAI Plugins Directory;
4. зарегистрировать сайт в Google Search Console, Bing Webmaster Tools и
   Яндекс Вебмастере, включить IndexNow для опубликованных ответов;
5. затем добавить MCP в Smithery, проверить автоматическое появление в Glama,
   добавить API в APIs.io и только после этого заниматься вторичными каталогами.

Это две параллельные воронки, а не одна:

| Воронка | Что становится обнаруживаемым | Кто должен сделать первый шаг |
|---|---|---|
| Web/AI search | страницы вопросов, ответы, профиль человека | поисковик или агент с web search |
| MCP/plugin directory | функции `ask_question` и `get_answer` | пользователь подключает/выбирает плагин либо клиент ищет registry |
| WebMCP | инструменты текущей открытой страницы | пользователь или агент уже открыл askhuman.ru |

Важно не обещать, что публикация в каталоге заставит любого автономного агента
самостоятельно задавать вопросы. Реальный эффект MCP-каталогов — сделать сервис
**доступным для установки, выбора и вызова** в поддерживаемых клиентах. Решение о
вызове всё равно принимает хост, модель и часто пользователь.

## Что уже сделано хорошо

По коду проект уже заметно лучше подготовлен к машинному чтению, чем типичный
MVP:

- `/for-agents` объясняет назначение, ограничения, публичность данных и полный
  цикл submit → poll;
- `/openapi.yaml` описывает POST и GET, статусные ответы, лимиты и
  `Idempotency-Key`;
- `/llms.txt`, `/robots.txt` и `/sitemap.xml` генерируются сервером;
- HTML рендерится на сервере, есть canonical URL, метаописания и JSON-LD;
- ожидающие и отклонённые вопросы закрыты `noindex`, а опубликованные ответы
  индексируются;
- sitemap включает опубликованные ответы и `lastmod`;
- API предупреждает о публичности, поддерживает идемпотентность, rate limiting и
  рекомендуемый интервал polling.

Это хорошая основа. Главный отсутствующий элемент для агентных каталогов — MCP.

Перед отправкой OpenAPI во внешние каталоги желательно заменить относительный
`servers: [{ url: "/" }]` на явный production URL `https://askhuman.ru`: относительный
URL допустим в OpenAPI, но абсолютный адрес уменьшает неоднозначность при импорте
спецификации сторонними инструментами.

Есть одна конкретная SEO-ошибка в текущем шаблоне опубликованного ответа:
`templates/question.php` объявляет страницу как `WebPage`, хотя Google
обрабатывает `Question` для Q&A rich results только внутри `QAPage`.[^17] Там же
автором **вопроса** указан человек, хотя он автор ответа. Следует:

- заменить корневой `@type` опубликованной страницы на `QAPage`;
- оставить человека автором `acceptedAnswer`;
- у вопроса либо указать настоящего автора, если он публично известен и на это
  есть согласие, либо вовсе убрать необязательный `author`;
- `datePublished` вопроса брать из `q.created_at`, а для ответа добавить его
  собственные `datePublished`/`dateModified` из записи ответа;
- после деплоя прогнать несколько URL через Rich Results Test и URL Inspection.

## Рекомендуемый продуктовый интерфейс для агентов

### 1. Удалённый MCP-сервер

Официальный MCP Registry поддерживает удалённые серверы через поле `remotes` и
рекомендует Streamable HTTP; старый SSE transport уже помечен deprecated.[^4]
Достаточно одного публичного endpoint:

```text
https://askhuman.ru/mcp
```

MCP может быть тонким адаптером к существующей бизнес-логике, без второго
хранилища и без дублирования Telegram-уведомлений.

Минимальные tools:

| Tool | Действие | Аннотации |
|---|---|---|
| `ask_question` | создаёт публичный вопрос и возвращает ID, public URL и status URL | `readOnlyHint: false`, `destructiveHint: true`, `openWorldHint: true`; `idempotentHint: true` только если MCP гарантированно передаёт стабильный ключ повторной попытки |
| `get_answer` | читает состояние конкретного ID и готовый ответ | `readOnlyHint: true`, `destructiveHint: false`, `openWorldHint: false` |

`destructiveHint: true` здесь нужен не потому, что данные удаляются, а потому,
что вызов публикует данные и отправляет сообщение человеку — это внешнее,
потенциально необратимое действие. OpenAI прямо относит отправку сообщений и
форм к consequential/destructive действиям; аннотации должны соответствовать
фактическому поведению.[^7]

Описание write-tool должно сразу задавать границы. Рабочий вариант:

> Use this when normal web search did not provide a sufficiently reliable answer
> and a real human's local knowledge, experience, opinion, or observation may
> help. The question and answer are public. Do not use this for secrets, personal
> data, emergencies, routine web lookups, or repeated polling.

Название для каталога лучше сделать более уникальным, чем общий `Ask a Human`:

```text
Ask a Human by 0soft
```

OpenAI рекомендует строить tool name как «домен + действие», начинать описание с
`Use this when…`, отдельно задавать недопустимые сценарии и проверять metadata на
позитивных и негативных запросах.[^8]

### 2. Асинхронный результат

Существующая пара `ask_question` + `get_answer` правильная для MVP. Не следует
держать MCP-вызов открытым до человеческого ответа. Результат создания должен
возвращать:

- `id`;
- `status: waiting_for_human`;
- абсолютные `question_url` и `status_url`;
- `poll_after_seconds`;
- короткую инструкцию сохранить ID и не обещать фоновое ожидание, если клиент
  не умеет продолжать задачу позже.

Для автономного использования узкое место — не протокол, а то, что многие
агентные сессии не сохраняют задачу между запусками. Поэтому public URL должен
быть полноценным конечным артефактом, который агент может отдать пользователю.

### 3. Безопасность и качество

Открытый write-tool привлечёт больше автоматического трафика, чем HTML-форма.
До каталогов нужны:

- действующие rate limits по IP/сети и глобальный аварийный лимит;
- идемпотентность на уровне общей бизнес-логики REST и MCP;
- максимальные размеры полей и отказ от произвольных вложений/URL fetch;
- явное подтверждение публичности в input schema;
- безопасная модерация до индексации;
- возможность быстро выключить только `ask_question`, оставив чтение ответов;
- понятные privacy, terms, support и abuse-contact URL.

## Где и как регистрировать

### Приоритет 1: официальный MCP Registry

Это наиболее каноничный нейтральный реестр метаданных MCP, хотя на дату
исследования он всё ещё имеет статус preview.[^1] Он хранит метаданные, а не
исполняет сервер. Для remote-only сервера npm-пакет не нужен: используется
`remotes`.[^4]

Рекомендуемый `server.json` после появления endpoint:

```json
{
  "$schema": "https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json",
  "name": "ru.0soft/ask-a-human",
  "title": "Ask a Human by 0soft",
  "description": "Ask a real human a public question when normal web search is insufficient, then retrieve the answer later.",
  "version": "1.0.0",
  "repository": {
    "url": "PUBLIC_REPOSITORY_URL",
    "source": "github"
  },
  "websiteUrl": "https://askhuman.ru/for-agents",
  "remotes": [
    {
      "type": "streamable-http",
      "url": "https://askhuman.ru/mcp"
    }
  ]
}
```

Текущую schema URL лучше получать командой `mcp-publisher init`, а не навечно
копировать из этого отчёта: Registry в preview. Имя `ru.0soft/ask-a-human`
соответствует reverse-DNS логике доменной аутентификации, но его нужно
обязательно проверить через `mcp-publisher validate`/`publish`; если правила
имени изменятся или цифровой label окажется проблемой, безопасный запасной путь
— GitHub namespace `io.github.<owner>/ask-a-human`.[^3]

Порядок:

1. установить текущий релиз `mcp-publisher` из официального репозитория;
2. запустить `mcp-publisher init` и заполнить `server.json`;
3. выбрать доменную авторизацию `mcp-publisher login dns --domain askhuman.ru ...`
   либо HTTP challenge в `/.well-known/mcp-registry-auth`; Registry требует
   reverse-DNS namespace для domain auth.[^2]
4. проверить JSON и публичную доступность `/mcp`;
5. выполнить `mcp-publisher publish`;
6. найти запись через публичный Registry API и сохранить URL карточки.

Не надо отдельно искать мифическую форму «GitHub MCP Registry». GitHub OAuth —
это способ авторизации и namespace официального Registry, а не доказанный
отдельный обязательный канал публикации.

### Приоритет 1: OpenAI Plugins Directory

Это отдельная публикация: наличие сервера в официальном MCP Registry не создаёт
листинг OpenAI автоматически. На текущей платформе можно отправить **remote
MCP-only plugin** без custom UI; нужен стабильный публичный HTTPS endpoint.[^5]
После review и ручной публикации он появляется в едином Plugins Directory для
ChatGPT и Codex.[^6]

Порядок:

1. в OpenAI Platform у отправителя включить роль `Apps Management: Write`;
2. пройти individual или business identity verification;
3. подготовить публичные website, support, privacy и terms URL, совпадающие с
   identity издателя;
4. открыть Plugin submission portal → `Create plugin` → `With MCP` →
   `Universal`;
5. указать `https://askhuman.ru/mcp`, пройти domain challenge
   `/.well-known/openai-apps-challenge`, выполнить `Scan Tools`;
6. проверить tool names, descriptions, input/output schema и все аннотации;
7. добавить starter prompts, пять позитивных и три негативных test cases;
8. отправить на review, а после одобрения отдельно нажать Publish.[^5]

Рекомендуемые starter prompts:

- “Web search did not answer this local question. Ask the human on 0soft and
  give me the tracking link.”
- “Check whether the human has answered question `<id>`.”
- “I need a first-hand observation from Nizhny Novgorod; use Ask a Human only
  after searching the web.”

Негативные тесты должны подтверждать, что tool не вызывается для обычного
фактического поиска, секретных/личных данных и срочных медицинских или
экстренных ситуаций.

OpenAI отдельно отмечает: после публикации приложение можно найти по точному
имени и прямой ссылке, но расширенная проактивная дистрибуция доступна лишь
некоторым приложениям и её нельзя запросить. Поэтому листинг полезен, но не
гарантирует органические вызовы.[^7]

### Приоритет 2: Smithery

Smithery принимает публичный remote MCP на `https://smithery.ai/new` или через:

```bash
smithery mcp publish "https://askhuman.ru/mcp" -n @NAMESPACE/ask-a-human
```

Для hosted server нужен Streamable HTTP. Smithery сканирует tool metadata; если
сканирование блокируется защитой, документация предлагает разрешить их bot либо
дать статическую карточку `/.well-known/mcp/server-card.json`.[^9] Не отдавать
`403` на обычный неавторизованный probe, если по смыслу должен быть `401`.

### Приоритет 2: Glama

Glama объединяет MCP-каталог с автоматическими security/quality проверками и
заявляет, что импортирует официальный Registry.[^10] Поэтому правильный порядок:

1. сначала опубликоваться в официальном Registry;
2. дождаться/проверить появление в Glama;
3. если записи нет либо она не привязана к владельцу — использовать Add Server
   и GitHub-подтверждение;
4. исправить результаты сканирования, а не создавать дубликат.

### Приоритет 2: APIs.io и APIs.json

У сервиса уже есть OpenAPI 3.1, поэтому он подходит для API discovery. На
`https://apis.io/add/` достаточно передать URL сайта: каталог ищет well-known
metadata, OpenAPI, `llms.txt` и MCP endpoint.[^11] В отличие от обычного списка
ссылок, сам APIs.io предлагает агентный поиск каталога через MCP, поэтому этот
канал практически полезен.

До регистрации стоит добавить `/.well-known/apis.json` со ссылками на:

- human docs `/for-agents`;
- OpenAPI `/openapi.yaml`;
- MCP `/mcp`;
- privacy, terms и contact/support.

APIs.json позиционируется как машиночитаемый индекс API — аналог sitemap для API
контрактов — и используется APIs.io для discovery.[^12]

### Приоритет 3: Postman Public API Network

Создать public workspace, импортировать `/openapi.yaml`, опубликовать collection
с двумя рабочими примерами и ссылкой на `/for-agents`. Публичные workspaces и
collections доступны в Postman API Network и могут попадать в обычный поиск.[^13]
Это прежде всего канал разработчиков и интеграторов, а не прямой источник
автоматических вызовов модели.

### Приоритет 3: GitHub и awesome lists

Если MCP-адаптер будет open source, отдельный публичный repository улучшит
доверие и даст installable/reference implementation. Рекомендуемые topics:
`mcp-server`, `ai-agents`, `human-in-the-loop`, `ask-a-human`.

Основной `punkpeye/awesome-mcp-servers` принимает только серверы с публичным
GitHub repository, которые можно установить и запустить самостоятельно;
remote-only серверы он направляет в `awesome-remote-mcp-servers`.[^14] Поэтому:

- remote-only проект подавать в список remote servers;
- в основной список идти только если опубликован действительно запускаемый код,
  а не ссылка на hosted endpoint;
- соблюдать одну строку на сервер, категорию и алфавитный порядок.

### Приоритет 3–4: прочие MCP-каталоги

| Каталог | Решение | Почему |
|---|---|---|
| [`mcpservers.org/submit`](https://mcpservers.org/submit) | добавить после основных | есть бесплатная форма; premium необязателен[^28] |
| [`mcp.so/submit?type=server`](https://mcp.so/submit?type=server) | только если оправдан платный тест | на дату исследования обычная submission стоит $39; это paid distribution, не стандарт[^29] |
| PulseMCP | перепроверить форму в день запуска | каталог полезен, но текущую доступность submission не удалось надёжно подтвердить |
| cursor.directory | низкий приоритет | аудитория уже и листинг не заменяет официальный Registry |

Платный листинг следует оценивать по измеримым подключениям/вызовам, а не по
`dofollow` и обещаниям featured placement.

## Поисковая и AI-индексация

### Google Search Console

1. Добавить Domain property для `askhuman.ru` и подтвердить DNS TXT.
2. Отправить `https://askhuman.ru/sitemap.xml`.
3. Через URL Inspection проверить главную, `/for-agents`, профиль человека,
   архив и 3–5 хороших опубликованных ответов.
4. После исправления QAPage проверить rich results.

Sitemap и ручной recrawl — только подсказки: Google не гарантирует обход или
индексацию и предупреждает, что процесс может занять от нескольких дней до
нескольких недель.[^15][^16]

### Bing Webmaster Tools + IndexNow

1. Добавить/импортировать сайт в Bing Webmaster Tools.
2. Отправить sitemap.
3. Настроить IndexNow key и уведомлять только о конечных публичных URL:
   опубликован новый ответ, ответ отредактирован, ответ снят с публикации.
4. Не отправлять ожидающие `noindex`-страницы как новый индексируемый контент.

IndexNow уведомляет участвующие поисковые системы об изменении URL, но не
гарантирует индексацию.[^18]

### Яндекс Вебмастер

Для домена `.ru`, русскоязычного владельца и локальных тем Нижнего Новгорода это
не факультативный канал:

1. добавить сайт и подтвердить владение;
2. отправить sitemap;
3. проверить диагностику индексирования и региональность;
4. следить, чтобы канонические English URL и будущие русские версии не создавали
   дубли; при локализации использовать отдельные URL и `hreflang`.

Яндекс рекомендует Sitemap как один из способов сообщить роботу о страницах,
которые должны участвовать в поиске.[^19]

### Какие AI-краулеры действительно важны

Текущий `robots.txt`:

```text
User-agent: *
Allow: /
Disallow: /admin
Sitemap: https://askhuman.ru/sitemap.xml
```

уже разрешает известных ботов, если CDN/WAF не блокирует их отдельно. Не нужно
создавать отдельную секцию для каждого user-agent. Более того, отдельная секция
может случайно потерять `Disallow: /admin`, потому что специфичная группа может
применяться вместо wildcard.

Проверять нужно две вещи: доступ в `robots.txt` и реальный пропуск на уровне
CDN/WAF. User-Agent нельзя считать доказательством личности; для аналитики и
allowlist сверять опубликованные IP/rDNS там, где поставщик их предоставляет.

| Поставщик | Полезные идентификаторы | Назначение |
|---|---|---|
| OpenAI | `OAI-SearchBot` | поверхности ChatGPT Search; самый релевантный для цитирования |
| OpenAI | `ChatGPT-User` | запрос страницы по инициативе пользователя |
| OpenAI | `GPTBot` | обучение моделей; отдельное решение, не условие ChatGPT Search |
| Anthropic | `Claude-SearchBot`, `Claude-User`, `ClaudeBot` | search, user fetch и training — независимые роли |
| Perplexity | `PerplexityBot`, `Perplexity-User` | search index и user-requested fetch |

OpenAI прямо разделяет эти три режима и указывает, что правила для одного бота
не управляют двумя другими.[^20] У Anthropic также три отдельных crawler role.[^21]
Perplexity публикует назначения и IP-диапазоны своих краулеров.[^22]

`Google-Extended` — не отдельный crawler user-agent, а robots product token,
управляющий использованием контента для Gemini training/grounding. Он не влияет
на включение и ранжирование в Google Search.[^23] Явно добавлять его ради
«обнаружения» бессмысленно; решение о разрешении нужно принимать как политику
использования данных.

### Контент важнее файлов-манифестов

Агентному поиску нужны не десятки пустых страниц, а устойчивые, доступные без JS
страницы с конкретным вопросом и хорошо атрибутированным ответом. Перед активным
продвижением полезно иметь хотя бы 5–10 настоящих ответов, показывающих сильные
темы человека: его реальные профессиональные области и локальный опыт, который
он может подтвердить лично.

Каждый ответ должен:

- отвечать на вопрос в первых абзацах;
- отделять наблюдение/мнение от проверяемого факта;
- иметь видимые даты вопроса, ответа и обновления;
- ссылаться на профиль человека;
- иметь уникальные title и description;
- по возможности ссылаться на первичные источники, если ответ содержит
  проверяемые утверждения.

### Что делать с `llms.txt`

Существующий `/llms.txt` стоит оставить: он дёшев, понятен и удобен агенту,
который уже знает адрес. Но это экспериментальный proposal, а не стандарт
регистрации.[^24] Google прямо пишет, что специальные AI-файлы или особая
оптимизация для AI search не требуются, а `llms.txt` Google Search не использует.[^25]

Наблюдательное исследование Ahrefs по 137 210 доменам в мае 2026 года сообщило,
что 97% найденных `llms.txt` не получили ни одного запроса за период наблюдения;
это не доказывает бесполезность формата, но хорошо показывает его текущий низкий
приоритет как канала acquisition.[^26]

Следовательно, не стоит тратить ранний бюджет на десятки `llms.txt` directories.
Сначала MCP, поисковые панели, IndexNow и качественные ответы.

## WebMCP: полезно, но позже

WebMCP позволяет совместимому агенту обнаружить tools **после того, как он уже
открыл страницу** в поддерживаемом браузере. Инструменты принадлежат текущей
странице и исчезают после ухода с неё; отдельная MCP-установка не нужна.[^27]
Поэтому WebMCP повышает конверсию визита в действие, но не решает глобальное
обнаружение сайта.

После основного MCP можно зарегистрировать на `/for-agents` и главной странице
тот же `ask_question` через JavaScript и существующий API. На текущей реализации
ChatGPT site tools declarative form attributes не поддерживаются: требуется
top-level JavaScript registration.[^27]

Из-за публичной отправки browser safety review может потребовать подтверждение,
и это ожидаемое корректное поведение, а не UX-баг.

## Чего сейчас не делать

- Не публиковать A2A Agent Card: Ask a Human сейчас сервис-инструмент, а не
  самостоятельный A2A-агент с task lifecycle.
- Не строить legacy `/.well-known/ai-plugin.json` как основной OpenAI-канал:
  текущая публичная схема — Plugins Directory с MCP/skills.[^5]
- Не перечислять каждого crawler в `robots.txt`, если общий wildcard уже всё
  разрешает; проверять WAF отдельно.
- Не считать `llms.txt` заменой MCP, sitemap или внешних упоминаний.
- Не платить всем каталогам до появления attribution и хотя бы первых данных о
  конверсии.
- Не создавать сотни искусственных Q&A ради SEO: для сервиса, основанного на
  доверии к реальному человеку, это особенно быстро разрушит качество.
- Не обещать SLA или «агент дождётся ответа», пока нет реального фонового
  механизма возобновления в конкретном агентном клиенте.

## План запуска

### Этап A — готовность к регистрации (1–3 дня разработки)

- [ ] Исправить `QAPage`, автора и даты в JSON-LD.
- [ ] Добавить публичные Terms и Support/Contact URL; privacy уже существует.
- [ ] Реализовать `/mcp` на Streamable HTTP с двумя tools.
- [ ] Вынести REST и MCP в одну бизнес-логику идемпотентности/лимитов.
- [ ] Указать абсолютный production server URL в OpenAPI перед каталогами.
- [ ] Добавить `/mcp` в `/for-agents` и `/llms.txt`.
- [ ] Добавить `/.well-known/apis.json`.
- [ ] При необходимости добавить Smithery server card.
- [ ] Проверить, что WAF пропускает registry scanners и поисковых ботов.
- [ ] Подготовить 5–10 качественных опубликованных ответов.

### Этап B — канонические каналы (день запуска)

- [ ] Google Search Console: domain verify + sitemap + inspect основных URL.
- [ ] Bing Webmaster Tools: verify/import + sitemap + IndexNow.
- [ ] Яндекс Вебмастер: verify + sitemap + диагностика.
- [ ] MCP Inspector: полный submit/poll сценарий и негативные сценарии.
- [ ] Official MCP Registry: domain/GitHub auth + publish.
- [ ] OpenAI Platform: MCP-only plugin submission.

### Этап C — дистрибуция (следующие 7 дней)

- [ ] Smithery.
- [ ] Проверить/claim Glama.
- [ ] APIs.io.
- [ ] Postman Public API Network.
- [ ] Подходящий awesome remote MCP list; основной список — только при
  self-hostable open-source реализации.
- [ ] Бесплатный mcpservers.org; платные каталоги только как измеримый эксперимент.

### Этап D — контент и внешние сигналы

- [ ] Технический launch post с честным описанием эксперимента и API/MCP.
- [ ] Show HN, релевантные MCP-сообщества, Habr/dev.to — по одному содержательному
  материалу, без одинакового спама.
- [ ] Добавлять внутренние ссылки между связанными ответами и профильными темами.
- [ ] Каждую неделю публиковать только действительно полезные ответы.

## Как измерять результат

Разделить метрики по этапам воронки:

| Этап | Метрики |
|---|---|
| Discovery | indexed URLs, Search Console/Bing/Yandex impressions, AI crawler hits, referral domains |
| Availability | карточки Registry/Plugins/Smithery/Glama, успешный health/metadata scan |
| Activation | MCP connections, `ask_question`/`get_answer` calls, unique clients |
| Completion | доля ответов, доля retrieval после ответа, median time-to-answer |
| Quality | declines, duplicates, spam/rate-limit events, утечки личных данных |

Для логов сохранять нормализованный channel (`rest`, `mcp`, `webmcp`, `html`) и,
если клиент передаёт безопасный идентификатор, source (`openai`, `smithery`,
`postman` и т. п.). Не доверять одному User-Agent как идентичности клиента.

Отдельно завести «golden prompts» и прогонять их после изменения metadata:

- 5 прямых запросов, где Ask a Human точно уместен;
- 5 косвенных запросов после неудачного web search;
- 5 негативных запросов, где инструмент не должен вызываться;
- 3 privacy/safety запроса, которые должны быть отклонены;
- полный сценарий submit → сохранение ID → delayed get_answer.

Ключевой показатель не число каталогов, а число **полезных завершённых циклов**,
в которых человек ответил, агент позже получил ответ, а публичная страница стала
качественным повторно используемым источником.

## Итоговая приоритизация

| Приоритет | Действие | Ожидаемый эффект |
|---|---|---|
| P0 | MCP endpoint + корректные metadata/safety annotations | превращает сайт в устанавливаемый агентный инструмент |
| P0 | QAPage + качественные ответы + public terms/support | готовность к search и review |
| P1 | Official MCP Registry | каноническая MCP-discovery |
| P1 | OpenAI Plugins Directory | обнаружение и подключение в ChatGPT/Codex |
| P1 | GSC + Bing/IndexNow + Яндекс | web/AI-search discovery опубликованных ответов |
| P2 | Smithery + Glama + APIs.io | покрытие популярных каталогов и интеграторов |
| P3 | Postman + GitHub/awesome remote list | developer discovery и доверие |
| P4 | бесплатные вторичные каталоги | дополнительное покрытие |
| P5 | платные listings и llms.txt directories | только после измерения основных каналов |

## Источники

[^1]: Model Context Protocol, [Official MCP Registry Quickstart](https://modelcontextprotocol.io/registry/quickstart) и [Registry repository](https://github.com/modelcontextprotocol/registry).
[^2]: Model Context Protocol, [Registry authentication](https://modelcontextprotocol.io/registry/authentication).
[^3]: Model Context Protocol Registry, [current `server.json` schema source](https://github.com/modelcontextprotocol/registry/blob/main/docs/reference/server-json/draft/server.schema.json).
[^4]: Model Context Protocol, [Publishing Remote Servers](https://modelcontextprotocol.io/registry/remote-servers).
[^5]: OpenAI, [Submit plugins](https://developers.openai.com/plugins/deploy/submission).
[^6]: OpenAI, [Submit plugins — public publishing flow](https://developers.openai.com/plugins/deploy/submission#public-publishing-flow).
[^7]: OpenAI, [MCP server review requirements](https://developers.openai.com/plugins/deploy/app-review).
[^8]: OpenAI, [Optimize metadata](https://developers.openai.com/plugins/guides/optimize-metadata).
[^9]: Smithery, [Publishing remote servers](https://smithery.ai/docs/build/publish).
[^10]: Glama, [MCP methodology](https://glama.ai/mcp/methodology) и [MCP FAQ](https://glama.ai/mcp/faq).
[^11]: APIs.io, [Add an API](https://apis.io/add/) и [API directory](https://apis.io/).
[^12]: APIs.json, [A machine-readable index for APIs](https://apisjson.org/) и [schema](https://apisjson.org/schema/).
[^13]: Postman, [Publish public APIs](https://learning.postman.com/docs/postman-api-network/showcase/publish/public-apis/) и [Public API Network overview](https://learning.postman.com/docs/postman-api-network/overview/).
[^14]: punkpeye, [awesome-mcp-servers contribution rules](https://github.com/punkpeye/awesome-mcp-servers/blob/main/CONTRIBUTING.md).
[^15]: Google Search Central, [Build and submit a sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).
[^16]: Google Search Central, [Ask Google to recrawl URLs](https://developers.google.com/search/docs/crawling-indexing/ask-google-to-recrawl).
[^17]: Google Search Central, [Q&A structured data](https://developers.google.com/search/docs/appearance/structured-data/qapage).
[^18]: Bing Webmaster Tools, [IndexNow](https://www.bing.com/webmasters/help/indexnow-0z209wby) и [URL submission](https://www.bing.com/webmasters/help/url-submission-62f2860b).
[^19]: Yandex Webmaster, [Sitemap](https://yandex.com/support/webmaster/en/indexing-options/sitemap) и [indexing recommendations](https://www.yandex.com/support/webmaster/en/recommendations/indexing).
[^20]: OpenAI, [OpenAI crawlers](https://developers.openai.com/api/docs/bots).
[^21]: Anthropic, [Web crawlers and crawler controls](https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler).
[^22]: Perplexity, [Perplexity crawlers](https://docs.perplexity.ai/docs/resources/perplexity-crawlers).
[^23]: Google, [Common crawlers — Google-Extended](https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers).
[^24]: llms.txt project, [The llms.txt proposal](https://llmstxt.org/).
[^25]: Google Search Central, [AI features and your website](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide).
[^26]: Ahrefs, [We Analyzed 1,000 Domains' llms.txt Files](https://ahrefs.com/blog/llmstxt-study/), опубликовано 15 июня 2026 года.
[^27]: OpenAI, [Site tools / WebMCP](https://learn.chatgpt.com/docs/webmcp).
[^28]: MCP Servers, [server submission form](https://mcpservers.org/submit).
[^29]: MCP.so, [server submission form and current price](https://mcp.so/submit?type=server).
