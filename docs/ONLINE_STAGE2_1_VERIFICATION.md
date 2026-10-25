# Этап 2.1. Проверка и фиксация результатов перед PHP API

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Код приложения / PHP / SQL / CI / деплой / коммит:** на этом этапе не создавались и не менялись (только проверка).

---

## 1. Git

| Проверка | Результат |
|----------|-----------|
| Ветка | `feature/online-migration` |
| `reset` / `clean` / принудительный checkout | не выполнялись |
| Секреты в рабочей копии (`.env`, ключи, пароли) | не найдены |
| Упоминания имён секретов в docs | только как план (`FTP_PASSWORD` и т.п.), без значений |
| `.gitignore` | `/out/`, `/.next/`, `/node_modules`, `.env*` — менять не требуется |

Состояние на момент проверки: изменения этапов 1–2 в working tree (modified + untracked), **коммит не создавался**.

---

## 2. Этап 1 — артефакты

| Артефакт | Статус |
|----------|--------|
| `scripts/run-vitest.mjs` | есть |
| `package.json` → `test` / `test:watch` через launcher | ок |
| `SPEC.md` | есть (~322 строки); автоматически не перезаписывался |
| `docs/ONLINE_STAGE1_STABILIZATION.md` | есть |

---

## 3. Этап 2 — артефакты

| Артефакт | Статус |
|----------|--------|
| `next.config.ts` (`output: 'export'`, `trailingSlash: true`, без PPR) | ок |
| `src/lib/routes.ts` | есть |
| `src/components/routing/RequireQueryId.tsx` | есть |
| `/clients/detail|edit`, `/orders/detail|edit` | есть |
| динамические `[id]` | удалены с диска |
| ссылки через `routes.*` | ок |
| `docs/ADR-001-static-export-routes.md` | принято (вариант A) |
| `docs/ONLINE_STAGE2_STATIC_EXPORT.md` | есть |
| `out/` — секреты / php / env | не найдены |

---

## 4. Повторные проверки (выполнены на этапе 2.1)

| Команда | Результат |
|---------|-----------|
| `npm run typecheck` | OK (exit 0) |
| `npm run lint` | OK (exit 0) |
| `npm test` | OK — 9 files, 39 tests |
| `npm run build` | OK — все маршруты `○ Static` |

После сборки в `out/` подтверждены:

- `index.html`
- `clients/`, `clients/detail/`, `clients/edit/`, `clients/new/`
- `orders/`, `orders/detail/`, `orders/edit/`, `orders/new/`
- `settings/`
- `404.html`
- `_next/static/chunks` (JS/CSS), media/fonts
- размер ~1.8 MB

---

## 5. Предлагаемый состав коммита (не выполнен)

**Включить:** исходники и config этапов 1–2, `scripts/run-vitest.mjs`, `SPEC.md`, `ONLINE_MIGRATION_PLAN.md`, все файлы `docs/` по online-миграции, удаления `[id]`.

**Не включать:** `out/`, `.next/`, `node_modules/`, `.env*`.

Коммит и push — только после отдельного подтверждения пользователя.

---

## 6. Риски

1. Старые URL вида `/clients/<uuid>` больше не работают.
2. Совместимость с REG.RU не доказана локальной сборкой.
3. При `output: 'export'` нет `next start` как production-сервера.
4. `cacheComponents` / PPR отключены осознанно (несовместимы с export).

---

## 7. План этапа 3 (черновик, не начат)

**Цель:** скелет PHP API + PDO + первая MySQL-миграция + healthcheck.

Черновая структура:

```text
api/
  public/index.php          # единственная точка входа под /api/
  src/                      # router, PDO, Controllers
  migrations/001_init.sql
config/                     # вне webroot, если хостинг позволяет
  local.php.example
```

Ограничения: static frontend; REG.RU / ISPmanager / PHP 8.3 / MySQL 8; без SSH; frontend и API деплоятся раздельно; API на том же домене под `/api/`; Dexie пока сохраняется; реальных данных для переноса нет.

### Вопросы к хостингу до реализации

1. Фактический document root `crm.devisd.online` и каталоги для статики vs `/api/`.
2. FTP или SFTP; отдельные пути/учётки для frontend и API.
3. Можно ли хранить PHP-конфиг **вне** public webroot.
4. Как безопасно применять SQL без SSH (phpMyAdmin / панель / одноразовый migrate-скрипт).
5. Подтверждены ли PHP 8.3 и MySQL 8 на тарифе.

---

## Итог

Этапы 1–2 проверены повторными командами; репозиторий готов к коммиту по списку выше и к этапу 3 после подтверждения. PHP API, SQL, CI и деплой на 2.1 **не создавались**.
