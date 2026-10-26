# Этап 2.2.1. Финальная проверка перед коммитом

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Статус:** индекс готов; `git commit` и `git push` **не выполнялись**.

---

## 1. Состояние staging до правок

Индекс этапов 1–2 уже был заполнен (34 файла). Untracked: `docs/ONLINE_STAGE2_2_COMMIT_PREP.md`.

`git diff --cached --check` → **exit 2** (trailing whitespace в Markdown).

Разрушающие команды (`reset`, `clean`, `checkout`, `restore`) не использовались.

---

## 2. Исправления

### Trailing whitespace

Удалены только пробелы и табуляции в конце строк в:

- `ONLINE_MIGRATION_PLAN.md`
- `docs/ADR-001-static-export-routes.md`
- `docs/ONLINE_AUDIT_STAGE0_REG_RU.md`
- `docs/ONLINE_STAGE1_STABILIZATION.md`
- `docs/ONLINE_STAGE2_1_VERIFICATION.md`
- `docs/ONLINE_STAGE2_STATIC_EXPORT.md`
- `docs/ONLINE_STAGE2_2_COMMIT_PREP.md`

Содержание документов не переписывалось. Для пунктов списка и однострочных метаданных trailing spaces не влияли на отображение после удаления.

### Индекс

Повторно добавлены исправленные Markdown-файлы.
Добавлен `docs/ONLINE_STAGE2_2_COMMIT_PREP.md`.
Этот отчёт (`docs/ONLINE_STAGE2_2_1_PRECOMMIT.md`) создан после проверки — при коммите его тоже нужно включить в индекс.

---

## 3. Повторные проверки (факт)

| Проверка | Результат |
|----------|-----------|
| `git diff --cached --check` | **exit 0** |
| `git diff --cached --stat` | **36 files**, +1842 / −123 (после добавления этого отчёта) |
| `git status --short` | только staged |
| Секреты / `.env*` | не в индексе |
| `node_modules/`, `.next/`, `out/` | не в индексе |
| Посторонние изменения | нет |

---

## 4. Итоговый список в индексе (36 файлов)

### Документация
| | Файл |
|---|------|
| A | `ONLINE_MIGRATION_PLAN.md` |
| A | `SPEC.md` |
| A | `docs/ADR-001-static-export-routes.md` |
| A | `docs/ONLINE_AUDIT_STAGE0_REG_RU.md` |
| A | `docs/ONLINE_STAGE1_STABILIZATION.md` |
| A | `docs/ONLINE_STAGE2_STATIC_EXPORT.md` |
| A | `docs/ONLINE_STAGE2_1_VERIFICATION.md` |
| A | `docs/ONLINE_STAGE2_2_COMMIT_PREP.md` |
| A | `docs/ONLINE_STAGE2_2_1_PRECOMMIT.md` (этот файл) |

### Конфиг и тесты
| | Файл |
|---|------|
| M | `next.config.ts` |
| M | `package.json` |
| A | `scripts/run-vitest.mjs` |

### Маршруты и UI
| | Файл |
|---|------|
| D | `src/app/clients/[id]/page.tsx`, `.../[id]/edit/page.tsx` |
| D | `src/app/orders/[id]/page.tsx`, `.../[id]/edit/page.tsx` |
| A | `src/app/clients/detail/*`, `src/app/clients/edit/*` |
| A | `src/app/orders/detail/*`, `src/app/orders/edit/*` |
| A | `src/lib/routes.ts`, `src/components/routing/RequireQueryId.tsx` |
| M | clients / orders / overview components, `navItems.ts` |

---

## 5. Смысл будущего коммита

- Стабилизация Vitest на Windows
- Static export + query-маршруты detail/edit
- Документация online-миграции (SPEC, ADR, отчёты этапов)

---

## 6. Ожидание

Этот отчёт уже добавлен в индекс. Коммит и push — только после подтверждения пользователя.
