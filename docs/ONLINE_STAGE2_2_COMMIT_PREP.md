# Этап 2.2. Подготовка коммита (без commit / push)

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Статус:** файлы **в индексе** (`git add`); `git commit` и `git push` **не выполнялись** — ожидание подтверждения.

---

## 1. Предварительные проверки

| Проверка | Результат |
|----------|-----------|
| Ветка | `feature/online-migration` |
| `git diff --check` (рабочее дерево до stage) | exit 0 |
| Секреты / `.env*` | не найдены |
| Посторонние изменения | нет |
| `out/`, `.next/` | существуют локально, **не** staging |

---

## 2. Состав индекса (34 файла, +1664 / −123)

### Документация
- `A` `ONLINE_MIGRATION_PLAN.md`
- `A` `SPEC.md`
- `A` `docs/ADR-001-static-export-routes.md`
- `A` `docs/ONLINE_AUDIT_STAGE0_REG_RU.md`
- `A` `docs/ONLINE_STAGE1_STABILIZATION.md`
- `A` `docs/ONLINE_STAGE2_STATIC_EXPORT.md`
- `A` `docs/ONLINE_STAGE2_1_VERIFICATION.md`

### Конфиг и тесты
- `M` `next.config.ts` — `output: 'export'`, `trailingSlash: true`
- `M` `package.json` — `test` / `test:watch` → launcher
- `A` `scripts/run-vitest.mjs`

### Маршруты
- `D` `src/app/clients/[id]/page.tsx`, `src/app/clients/[id]/edit/page.tsx`
- `D` `src/app/orders/[id]/page.tsx`, `src/app/orders/[id]/edit/page.tsx`
- `A` `src/app/clients/detail/*`, `src/app/clients/edit/*`
- `A` `src/app/orders/detail/*`, `src/app/orders/edit/*`
- `A` `src/lib/routes.ts`
- `A` `src/components/routing/RequireQueryId.tsx`

### Ссылки в UI
- `M` clients / orders / overview components, `navItems.ts`

### Не включено
- `.env*`, секреты, `node_modules/`, `.next/`, `out/`
- этот файл `docs/ONLINE_STAGE2_2_COMMIT_PREP.md` на момент staging ещё не существовал — **добавить в индекс перед коммитом**, если нужен в репозитории

---

## 3. `git diff --cached --check`

После этапа **2.2.1**: trailing whitespace в markdown удалён (только пробелы/табы в конце строк; содержание не переписывалось). Повторный `git diff --cached --check` — **exit 0**.

---

## 4. Подтверждения безопасности

- Секреты в индексе: **нет**
- Сборочные артефакты в индексе: **нет**
- Working tree вне staged: **чисто** (после 2.2.1)

---

## 5. Ожидаемое действие после подтверждения

1. `git commit` с сообщением по этапам 1–2
2. `git push` — только по отдельной просьбе

**Коммит и push не выполнялись.**
