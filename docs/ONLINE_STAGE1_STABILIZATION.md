# Этап 1. Стабилизация перед online-миграцией

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Код приложения / Dexie / бизнес-логика / PHP / export / деплой:** не менялись.

---

## Git

- Ветка: `feature/online-migration`
- Неожиданных изменений в tracked-коде не было (только ожидаемые docs / SPEC / scripts)
- Коммиты на этом этапе **не создавались**

## Vitest — причина и исправление

**Причина:** баг Vitest 5.0.x на Windows ([#10692](https://github.com/vitest-dev/vitest/issues/10692)): при `cwd` / пути CLI с буквой диска `c:` Node загружает второй экземпляр runtime; `describe()` падает с `Cannot read properties of undefined (reading 'config')`. Тесты сами по себе корректны — с `C:` все 39 тестов проходят без правок assertions.

**Исправление:** `scripts/run-vitest.mjs` + скрипты `test` / `test:watch` в `package.json`. Лаунчер поднимает и `cwd`, и путь к `vitest.mjs` с заглавной буквой диска. Тесты и проверки не ослаблялись.

Проверено: `npm test` из `c:\...` и принудительный spawn с lowercase cwd → **9 files / 39 tests passed**.

## SPEC.md

- `git log --all -- SPEC.md` — **пусто** (файл ни разу не коммитился в этом репозитории; в `init` его нет)
- В рабочей копии файл **есть** (пользователь восстановил вручную, ~322 строки, содержимое ТЗ Devis Studio)
- Автоматически не перезаписывался; выдуманная спецификация не создавалась
- Рекомендация: закоммитить `SPEC.md` вместе с остальными артефактами этапа, когда пользователь попросит commit

## ADR маршрутов

Файл: [`docs/ADR-001-static-export-routes.md`](./ADR-001-static-export-routes.md)

- **Рекомендация:** query-string статические маршруты (вариант A) для REG.RU без SSH
- Вариант B (path + Apache rewrite) — только после проверки на реальном хостинге
- Маршруты в коде и `output: 'export'` **не включались**

## Результаты команд

| Команда | Результат |
|---------|-----------|
| `npm run typecheck` | OK (exit 0) |
| `npm run lint` | OK (exit 0) |
| `npm test` | OK — 9 files, 39 tests |
| `npm run build` | OK — Next 16.4.2, без static export; `[id]` = Partial Prerender |

## Изменённые / новые файлы

| Файл | Действие |
|------|----------|
| `package.json` | `test` / `test:watch` → `scripts/run-vitest.mjs` |
| `scripts/run-vitest.mjs` | новый Windows-safe launcher |
| `docs/ADR-001-static-export-routes.md` | новый ADR |
| `docs/ONLINE_STAGE1_STABILIZATION.md` | этот отчёт |

Не трогались: Dexie, расчёты, маршруты app, `next.config`, PHP/SQL, CI deploy.

## Оставшиеся блокеры (к следующим этапам)

1. `output: 'export'` не включён — совместимость не доказана билдом
2. Динамические `/clients/[id]`, `/orders/[id]` (+ edit) — нужна реализация по ADR (Этап 2)
3. Нет `.github/workflows`
4. Нет PHP API / MySQL
5. `SPEC.md` и docs ещё untracked — не в git history
6. Upstream fix Vitest для casing может появиться в релизе > 5.0.5; лаунчер можно будет упростить после апгрейда

## Следующий этап (не начат)

**Этап 2:** spike Static Export — включить `output: 'export'`, реализовать выбранную схему маршрутов (A), проверить локально `out/`, зафиксировать `.htaccess` только если уйдём в B после проверки на REG.RU.
