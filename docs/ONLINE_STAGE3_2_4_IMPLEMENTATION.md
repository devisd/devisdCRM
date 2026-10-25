# Этап 3.2.4. Реализация согласованных ограничений модели данных

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Решение:** вариант C (гибридный).
**Commit / push / deploy / SQL на сервере:** не выполнялись. PHP API не менялся.

> **Уточнено последующими этапами (примечание 3.2.7).** Отчёт описывает состояние на момент 3.2.4. На этапе 3.2.5 шесть длинных полей (`other_contact`, `notes` клиента и заказа, `description` заказа и позиции, `comment`) переведены из `TEXT` в **`MEDIUMTEXT`** — упоминания `TEXT` в разделах 1–3 ниже относятся к 3.2.4. Лимиты Zod (20 000 кодовых точек Unicode) не менялись. Риск R1 устранён, см. `ONLINE_STAGE3_2_5_TEXT_CAPACITY.md`.

---

## 0. Расхождения с заданием и документами

| # | Расхождение | Как поступил |
|---|-------------|--------------|
| 1 | Задание ссылается на `docs/ONLINE_STAGE3_2_SCHEMA_DECISION.md` — такого файла нет. Вариант C описан в `docs/ONLINE_STAGE3_2_2_SCHEMA_DECISION.md`. | Работал по `ONLINE_STAGE3_2_2_SCHEMA_DECISION.md` (раздел 5, вариант C; раздел 6, список полей). |
| 2 | В разделе 2 задания не указан `orderItemSchema.unit` (64), но вариант C и раздел 4 задания (тесты) его включают. | Добавлен `.max(64)` — это часть варианта C, а не новое ограничение. |
| 3 | **Zod 4.6.7 считает длину строк для `.min()`/`.max()` в кодовых точках Unicode, а не в `string.length`** (`node_modules/zod/v4/core/checks.js`, `util.codePointLength`; проверено: `z.string().max(2)` принимает `"😀😀"` при `length === 4`). Документы 3.2.2 и 3.2.3, а также раздел 4.6 задания исходили из семантики `string.length`. | Ограничения реализованы как задано. Тест на эмодзи фиксирует фактическое поведение. Последствия — в разделе 7 (риск R1). Документы 3.2.2 и 3.2.3 не перезаписывались. |

---

## 1. Изменённые файлы

| Файл | Статус | Изменение |
|------|--------|-----------|
| `api/migrations/001_init.sql` | неотслеживаемый, изменён | `clients.other_contact`: `VARCHAR(255) NULL` → `TEXT NULL` |
| `src/lib/validation/schemas.ts` | изменён | Лимиты, константы, сообщения; `optionalTrimmed()` с необязательным лимитом |
| `src/lib/validation/clientForm.ts` | изменён | Лимиты формы клиента |
| `src/lib/validation/orderForm.ts` | изменён | Лимиты формы заказа и позиций |
| `src/lib/validation/paymentForm.ts` | изменён | Лимит комментария к оплате |
| `src/lib/validation/schemas.test.ts` | новый | Тесты схем сущностей |
| `src/lib/validation/clientForm.test.ts` | новый | Тесты формы клиента |
| `src/lib/validation/orderForm.test.ts` | изменён | + тесты лимитов |
| `src/lib/validation/paymentForm.test.ts` | изменён | + тесты комментария |
| `src/lib/services/backup.test.ts` | изменён | + тесты бэкапа на границах |
| `docs/ONLINE_STAGE3_2_4_IMPLEMENTATION.md` | новый | Этот отчёт |

UI-компоненты (`ClientForm.tsx`, `OrderForm.tsx`, `AddPaymentDialog.tsx`, `TextInput.tsx`), репозитории, сервисы, формат бэкапа и PHP **не менялись**.

---

## 2. Ограничения по полям

Общие константы в `schemas.ts`:

```ts
TEXT_LIMITS = { short: 255, unit: 64, long: 20_000 }
MAX_UINT32 = 4_294_967_295
maxLengthMessage(n) → "Не более 255 символов" / "Не более 20 000 символов"
```

`toLocaleString("ru-RU")` разделяет тысячи неразрывным пробелом. Формы импортируют эти же константы — числа нигде не продублированы.

| Сущность.поле | Было | Стало | SQL |
|---------------|------|-------|-----|
| `client.name` | `trim().min(1)` | + `.max(255)` | `VARCHAR(255)` |
| `client.contactName`, `phone`, `telegram`, `maxContact`, `source` | optional trimmed | + `.max(255)` | `VARCHAR(255)` |
| `client.email` | optional `email()` | + `.max(255)` (проверяется до формата) | `VARCHAR(255)` |
| `client.otherContact` | optional trimmed | + `.max(20000)` | **`TEXT`** (изменено) |
| `client.notes` | optional trimmed | + `.max(20000)` | `TEXT` |
| `order.title` | `trim().min(1)` | + `.max(255)` | `VARCHAR(255)` |
| `order.description`, `order.notes` | optional trimmed | + `.max(20000)` | `TEXT` |
| `order.number` | `int().positive()` | + `.max(4 294 967 295)` → 1…4 294 967 295 | `INT UNSIGNED` |
| `orderItem.name` | `trim().min(1)` | + `.max(255)` | `VARCHAR(255)` |
| `orderItem.unit` | `trim().min(1)` | + `.max(64)` | `VARCHAR(64)` |
| `orderItem.description` | optional trimmed | + `.max(20000)` | `TEXT` |
| `orderItem.sortOrder` | `int().nonnegative()` | + `.max(4 294 967 295)` → 0…4 294 967 295 | `INT UNSIGNED` |
| `payment.comment` | optional trimmed | + `.max(20000)` | `TEXT` |

Не тронуто: деньги, `quantity`, UUID, статусы, способы оплаты, даты (`dueDate`, `completedAt` по-прежнему `optionalTrimmed()` без лимита), `tags`, служебные поля.

### Семантика опциональных полей

`optionalTrimmed` стал функцией `optionalTrimmed(maxLength?)`. Без аргумента он строит ту же схему, что и раньше. Предобработка вынесена в `blankToUndefined` и не изменилась. Поведение зафиксировано тестом, который **сначала прогнан на неизменённом коде** (3/3 зелёные), а затем — после изменений:

- `undefined`, `""`, `"   "` → `undefined`;
- `"  Анна  "` → `"Анна"`;
- `null` → отклоняется (как и раньше).

Длина проверяется после `trim`, поэтому пробелы по краям в лимит не входят.

---

## 3. SQL

`api/migrations/001_init.sql`, таблица `clients`:

```sql
`other_contact` TEXT NULL,
```

Остальные столбцы, индексы и CHECK не менялись. Миграция нигде не применялась, поэтому `002` не нужна.

---

## 4. Формы и сообщения пользователю

| Форма | Поля с лимитом | Сообщение |
|-------|----------------|-----------|
| Клиент (`clientForm.ts`) | имя, контактное лицо, телефон, email, Telegram, MAX, источник — 255; другой контакт, заметки — 20 000 | «Не более 255 символов» / «Не более 20 000 символов» |
| Заказ (`orderForm.ts`) | название — 255; описание, заметки — 20 000; позиция: название — 255, единица — 64, описание — 20 000 | то же; «Не более 64 символов» |
| Оплата (`paymentForm.ts`) | комментарий — 20 000 | «Не более 20 000 символов» |

Существующие сообщения («Укажите имя или название», «Некорректный email», «Укажите единицу» и т. д.) сохранены. Для email первым показывается сообщение о длине: компоненты выводят первую ошибку поля.

### Почему не добавлен `maxLength` в UI

- Все поля с лимитами уже выводят ошибки Zod под полем (`errors.*` в `ClientForm`, `fieldErrors.*` и `items.N.*` в `OrderForm`, `errors.comment` в `AddPaymentDialog`) — пользователь получает понятное сообщение при сохранении.
- HTML `maxLength` считает UTF-16-единицы, а Zod — кодовые точки. С эмодзи атрибут оказался бы строже правила валидации и ограничил бы допустимый ввод.
- `maxLength` молча обрезает вставленный текст — потеря данных без предупреждения хуже явной ошибки.

---

## 5. Тесты

Инфраструктура проекта — Vitest, новых библиотек нет.

| Требование задания | Где проверено |
|--------------------|---------------|
| 1–2. Ровно на пределе — проходит, +1 — отклоняется | `schemas.test.ts` (15 полей), `clientForm.test.ts` (8 полей + email), `orderForm.test.ts` (6 полей), `paymentForm.test.ts` |
| 3. 255 / 64 / 20 000 = вариант C | `schemas.test.ts` → «limits match variant C» и параметризованные тесты по полям |
| 4. Границы `number` и `sortOrder` | `schemas.test.ts` → «numeric ranges» |
| 5. `number = 0`, отрицательные, дробные — отклоняются | там же |
| 6. Кириллица и эмодзи | все тесты длины строятся из `"ж"`; отдельный тест на эмодзи для каждого поля фиксирует подсчёт по кодовым точкам |
| 7. `undefined` / `null` / пустые строки | `schemas.test.ts` → «optional text semantics»; `clientForm.test.ts` → пустые поля превращаются в `undefined` |
| 8. Бэкап с превышением отклоняется | `backup.test.ts`: `data.clients.0.name`, `data.clients.0.otherContact`, `data.orderItems.0.unit`, `data.orders.0.number` |
| 9. Валидные бэкапы и импорт проходят | `backup.test.ts` (исходные тесты + бэкап ровно на границах), `backupOps.test.ts` без изменений — зелёный |

---

## 6. Проверки

| Команда | Результат |
|---------|-----------|
| `npm run typecheck` | OK |
| `npm run lint` | OK |
| `npm test` | OK — **11 файлов, 131 тест** (до этапа: 9 файлов, 39 тестов) |
| `npm run build` | OK — 11 маршрутов `○ Static` |
| `git diff --check` | exit 0 |
| `git diff --cached --check` | exit 0 (индекс пуст) |
| `out/` | нет `*.php`, `*.sql`, `.htaccess`, `local.php*`, `api/`, `config/` |

Первый прогон `npm test` дал 15 падений — все в тесте на эмодзи, построенном на неверном предположении о `string.length` (раздел 0, п. 3). Код не исправлялся; тест переписан под фактическое поведение Zod и прогон повторён.

---

## 7. Совместимость с JSON-бэкапом

- Формат не изменён: имена полей, `app`, `backupVersion: 1`, структура `data`.
- `backupFileSchema` строится из тех же `clientSchema`, `orderSchema`, `orderItemSchema`, `paymentSchema`, поэтому лимиты применяются к импорту автоматически, без дублирования.
- Бэкап с полем длиннее лимита теперь **отклоняется целиком** с указанием пути (`data.clients.0.name` и т. п.) — как и прочие ошибки валидации.
- **Совместимость с реальными пользовательскими бэкапами не подтверждена:** на этапе 3.2.3 в каталоге проекта не нашлось ни одного бэкапа, за его пределами поиск не выполнялся.

---

## 8. Оставшиеся риски и ограничения

| # | Риск | Комментарий |
|---|------|-------------|
| R1 | **Лимит 20 000 не гарантирует помещения в `TEXT`.** Zod считает кодовые точки; символ вне BMP (эмодзи и др.) занимает 4 байта UTF-8. 20 000 × 4 = 80 000 байт > 65 535 (лимит `TEXT`). Для кириллицы (2 байта) и большинства символов (≤ 3 байт) запас есть: 40 000–60 000 байт. | Требует решения, сам не менял (вне варианта C). Варианты: а) снизить `long` до 16 383 — гарантированно ≤ 65 532 байт; б) `MEDIUMTEXT` для 6 длинных полей — меняет SQL шире варианта C; в) оставить и проверять размер в байтах в PHP API (422) — Zod и API разойдутся на краю. Рекомендация — **а**: одна правка константы и тестов, без изменения SQL. **Статус: устранён на этапе 3.2.5** — принято решение сохранить лимит 20 000 и перевести 6 полей в `MEDIUMTEXT`, см. [`ONLINE_STAGE3_2_5_TEXT_CAPACITY.md`](./ONLINE_STAGE3_2_5_TEXT_CAPACITY.md). |
| R2 | Ошибка в документах 3.2.2 и 3.2.3: там сказано, что Zod считает `string.length` и строже MySQL. Фактически для `VARCHAR` Zod и MySQL считают одинаково — в символах. | Документы не перезаписывались. Вывод для `VARCHAR` стал лучше: Zod, MySQL и будущий PHP `mb_strlen()` используют одну единицу. |
| R3 | Валидность реальных данных не проверена. | Нужна проверка реального бэкапа перед импортом в MySQL (этап 3.2.3). |
| R4 | PHP API пока не проверяет лимиты. | Нужно реализовать на этапе CRUD: `mb_strlen()` ≤ лимита, 422 `validation_error`. |
| R5 | Даты (`orderDate`, `dueDate` и др.) по-прежнему принимают любую непустую строку. | Вне варианта C; решается при реализации API и импорта. |

---

## 9. Статус Git

Ветка `feature/online-migration`, HEAD `b2d1913 chore: prepare CRM for static hosting`. Индекс пуст.

**Изменённые отслеживаемые файлы:**

```text
 M .gitignore                              (этап 3.2)
 M src/lib/services/backup.test.ts
 M src/lib/validation/clientForm.ts
 M src/lib/validation/orderForm.test.ts
 M src/lib/validation/orderForm.ts
 M src/lib/validation/paymentForm.test.ts
 M src/lib/validation/paymentForm.ts
 M src/lib/validation/schemas.ts
```

**Неотслеживаемые:**

```text
?? api/                                    (этапы 3.2–3.2.1; 001_init.sql изменён на 3.2.4)
?? config/                                 (этап 3.2)
?? docs/ONLINE_STAGE2_2_2_COMMIT.md
?? docs/ONLINE_STAGE3_1_DESIGN.md
?? docs/ONLINE_STAGE3_2_1_AUDIT.md
?? docs/ONLINE_STAGE3_2_2_SCHEMA_DECISION.md
?? docs/ONLINE_STAGE3_2_3_BACKUP_AUDIT.md
?? docs/ONLINE_STAGE3_2_4_IMPLEMENTATION.md
?? docs/ONLINE_STAGE3_2_API_SKELETON.md
?? src/lib/validation/clientForm.test.ts
?? src/lib/validation/schemas.test.ts
```

Commit, push, deploy и SQL на сервере не выполнялись. Следующий этап не начат.
