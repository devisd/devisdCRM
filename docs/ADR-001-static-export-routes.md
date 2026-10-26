# ADR-001. Маршруты деталей для Next.js Static Export на REG.RU

**Статус:** принято (вариант A реализован на Этапе 2)
**Дата:** 2026-10-25
**Контекст:** Online-миграция; цель — `output: 'export'` + размещение на REG.RU shared hosting без SSH.

## Проблема

Страницы используют динамические сегменты:

- `/clients/[id]`, `/clients/[id]/edit`
- `/orders/[id]`, `/orders/[id]/edit`

Идентификаторы — UUID, создаваемые в runtime. На этапе `next build` полный список неизвестен, поэтому Static Export не может заранее сгенерировать HTML для каждого id. Сейчас приложение собирается как Node-сервер (Partial Prerender); `output: 'export'` ещё не включали.

Ссылки по приложению жёстко завязаны на path-параметры (`/clients/${id}`, `/orders/${id}/edit` и т.д.).

## Вариант A. Query string (статические маршруты)

Примеры URL:

- `/clients/detail?id=<uuid>`
- `/clients/edit?id=<uuid>`
- `/orders/detail?id=<uuid>`
- `/orders/edit?id=<uuid>`

Сборка отдаёт фиксированный набор HTML/JS. Id читается на клиенте из `searchParams` / `useSearchParams`.

| Плюсы | Риски |
|-------|--------|
| Полностью совместимо со static hosting без rewrite | Нужно переименовать/перенести страницы и обновить все `Link`/`router.push` |
| Предсказуемо на любом FTP-хостинге | URL меняются — закладки и старые ссылки сломаются (для личного CRM приемлемо) |
| Не зависит от `.htaccess` / возможностей Apache | Чуть менее «красивые» URL |
| Легко проверить локально через `npx serve out` | — |

## Вариант B. Path-URL + SPA fallback / Apache rewrite

Сохранить `/clients/<uuid>` и `/orders/<uuid>/edit`. При Static Export:

1. Собрать клиентский бандл так, чтобы роутинг обрабатывался на клиенте, **или**
2. На Apache настроить rewrite: неизвестные пути → соответствующий `index.html` / shell страницы.

На REG.RU shared hosting обычно доступен `.htaccess`, но без SSH нельзя надёжно отладить vhost и модули Apache.

| Плюсы | Риски |
|-------|--------|
| Сохраняет текущие URL и меньше правок в ссылках | Зависит от AllowOverride / `mod_rewrite` на конкретном тарифе |
| Привычный вид ссылок | Неверный rewrite даёт 404 при прямом заходе/обновлении страницы |
| — | Нужна проверка **на реальном хостинге** REG.RU (локальный `serve` ≠ Apache) |
| — | Совместимость Next 16 static export с «catch-all» / client-only dynamic routes нужно доказать spike-сборкой (Этап 2) |

## Рекомендация

**Предпочтительно Вариант A (query string)** для REG.RU без SSH:

1. Не зависит от конфигурации Apache.
2. Деплой — просто FTP каталога `out/` + отдельно PHP API.
3. Меньше «магии» на хостинге; проще CI и откат.

Вариант B допустим как запасной, **только после** успешного spike на staging REG.RU (прямой заход, F5, deep-link на detail/edit).

**Окончательный выбор по B** без проверки на хостинге делать нельзя: поведение rewrite на shared hosting нужно подтвердить фактом.

## Реализация (Этап 2)

- Маршруты: `/clients/detail/?id=`, `/clients/edit/?id=`, `/orders/detail/?id=`, `/orders/edit/?id=`
- Хелперы: `src/lib/routes.ts`
- `next.config.ts`: `output: 'export'`, `trailingSlash: true` (каталоги `index.html` без Apache rewrite)
- `.htaccess` не добавлялся
- Совместимость с REG.RU **не доказана** одной локальной сборкой — нужна проверка на staging FTP

## Критерий готовности на хостинге (ещё не выполнен)

- Прямой заход и F5 на detail/edit с query id
- CSS/JS грузятся с правильными путями
- Отдельный деплой API не затирает frontend (последующие этапы)
