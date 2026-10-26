# Этап 3.2.8. Подготовка изменений к коммиту

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Тип:** read-only проверка и план коммитов. Ничего не индексировалось и не коммитилось.
**Commit / push / deploy / SQL на сервере:** не выполнялись. Файл отчёта сохранён после этапа по запросу; содержание — из ответа этапа 3.2.8.

---

## 1. Итог

Дерево готово к индексированию: все 38 файлов можно безопасно закоммитить, секретов, реальной конфигурации, логов и сборочных файлов среди них нет. Предлагается три коммита; до подтверждения пользователя ничего не индексировалось.

---

## 2. Полный список изменений

Ветка `feature/online-migration`, HEAD `b2d1913`. Индекс пуст, удалённых файлов нет.

**Изменены (8):**

- `.gitignore` (+4 строки: `/config/local.php`, `/logs/`);
- `src/lib/services/backup.test.ts`;
- в `src/lib/validation/`: `schemas.ts`, `clientForm.ts`, `orderForm.ts`, `paymentForm.ts`, `orderForm.test.ts`, `paymentForm.test.ts`.

Итого по изменённым: +261 / −52 строки.

**Неотслеживаемые (30):**

- `api/` — 17 файлов: миграция, `public/index.php`, `public/.htaccess`, 13 файлов в `src/`, `tests/run.php`;
- `config/local.php.example`;
- 10 файлов в `docs/`: отчёты этапов 2.2.2, 3.1, 3.2 (`API_SKELETON`) и 3.2.1–3.2.7;
- `src/lib/validation/schemas.test.ts`, `src/lib/validation/clientForm.test.ts`.

Все правки этапа 3.2.7 на месте: комментарий в `schemas.ts`, переименованный тест, блок «Актуализация» в `API_SKELETON.md`, примечания в отчётах 3.2.2–3.2.4, сам файл отчёта 3.2.7.

Этот файл (`ONLINE_STAGE3_2_8_COMMIT_PLAN.md`) создан после проверки и в перечни выше не входит. Его можно добавить в коммит 3.

---

## 3. Предложенные коммиты

### Коммит 1 — `feat(api): add PHP API skeleton, health check and initial MySQL migration` (19 файлов)

- `api/public/index.php`, `api/public/.htaccess`;
- `api/src/bootstrap.php`, `Application.php`, `Config.php`, `Db.php`, `DatabaseProbe.php`;
- `api/src/Controllers/HealthController.php`;
- `api/src/Exceptions/ApiException.php`, `ConfigException.php`, `DatabaseUnavailableException.php`;
- `api/src/Http/Request.php`, `Router.php`, `JsonResponse.php`;
- `api/src/Logging/Logger.php`;
- `api/tests/run.php`;
- `api/migrations/001_init.sql` (уже с `MEDIUMTEXT` после 3.2.5);
- `config/local.php.example`;
- `.gitignore` — его правило защищает реальный `config/local.php` и логи, поэтому оно должно попасть в историю не позже самого API.

### Коммит 2 — `feat(validation): align Zod length and range limits with MySQL schema` (10 файлов)

- в `src/lib/validation/`: `schemas.ts`, `clientForm.ts`, `orderForm.ts`, `paymentForm.ts`, `schemas.test.ts`, `clientForm.test.ts`, `orderForm.test.ts`, `paymentForm.test.ts`;
- `src/lib/services/backup.test.ts`.

### Коммит 3 — `docs: add online migration stage reports 2.2.2–3.2.7` (10 файлов)

- в `docs/`: `ONLINE_STAGE2_2_2_COMMIT.md`, `ONLINE_STAGE3_1_DESIGN.md`, `ONLINE_STAGE3_2_API_SKELETON.md`, `ONLINE_STAGE3_2_1_AUDIT.md`, `ONLINE_STAGE3_2_2_SCHEMA_DECISION.md`, `ONLINE_STAGE3_2_3_BACKUP_AUDIT.md`, `ONLINE_STAGE3_2_4_IMPLEMENTATION.md`, `ONLINE_STAGE3_2_5_TEXT_CAPACITY.md`, `ONLINE_STAGE3_2_6_FINAL_AUDIT.md`, `ONLINE_STAGE3_2_7_AUDIT_FIXES.md`;
- при желании — этот отчёт `ONLINE_STAGE3_2_8_COMMIT_PLAN.md` (тогда 11 файлов, а сообщение коммита — «2.2.2–3.2.8»).

### Зависимости и порядок

Коммит 2 логически опирается на коммит 1: комментарий в `schemas.ts` ссылается на `api/migrations/001_init.sql`, а лимиты подобраны под её `VARCHAR`/`MEDIUMTEXT`. Технически frontend собирается и без `api/`. Коммит 3 ссылается на файлы обоих предыдущих, поэтому идёт последним. При порядке 1 → 2 → 3 каждый коммит остаётся согласованным: сборка и тесты frontend проходят на любом из них.

### Альтернатива

Отчёт `ONLINE_STAGE2_2_2_COMMIT.md` относится к прошлому этапу (коммит `b2d1913`), а `ONLINE_STAGE3_1_DESIGN.md` — к проектированию. Их можно вынести в отдельный коммит `docs: add stage 2.2.2 and 3.1 reports` перед коммитом 3. Решать пользователю.

---

## 4. Риски и файлы, требующие внимания

Ни один файл не нужно исключать, но эти включаются с оговорками:

| Файл | Почему с оговоркой |
|------|--------------------|
| весь `api/src/*`, `api/public/index.php`, `api/tests/run.php` | PHP ни разу не выполнялся: `php -l` и 23 PHP-теста не запускались, проверен только синтаксис JS-парсером. В сообщении коммита и описании нельзя писать, что API работает. |
| `api/tests/run.php` | Два совпадения со сканером секретов — фиктивные тестовые значения для проверки маскирования. Автоматические сканеры в CI могут на них сработать. |
| `api/public/.htaccess` | Поведение Apache на REG.RU не проверено. |
| `api/migrations/001_init.sql` | Не импортировалась ни в один MySQL; версия сервера и `sql_mode` неизвестны. |
| `docs/ONLINE_STAGE3_2_6_FINAL_AUDIT.md` | Сознательно фиксирует состояние до исправлений 3.2.7, часть замечаний в нём уже закрыта. Это нормально для исторического отчёта, а актуальный статус есть в отчёте 3.2.7. |

### Чего в коммит попасть не может

- `config/local.php`, `logs/` и `.env*` отсутствуют, а при появлении игнорируются (подтверждено `git check-ignore -v`);
- `out/`, `.next/`, `node_modules/`, `next-env.d.ts` и `tsconfig.tsbuildinfo` игнорируются;
- в `out/` нет PHP, SQL, `.htaccess` и каталогов `api/`, `config/`, `logs/`, `migrations/`.

### Приватная конфигурация

Код ищет её в `<родитель приватного api/>/config/local.php`: в репозитории это `<repo>/config/local.php` (игнорируется), на хостинге — `<home>/devis-crm/config/local.php`. Шаблон `config/local.php.example` содержит только `localhost`, порт 3306 и заглушки `__CHANGE_ME__`, которые API отвергает.

### Окончания строк

Git предупреждает `LF will be replaced by CRLF`. При `git add` файлы нормализуются по `core.autocrlf` — это ожидаемо и не меняет содержимого.

---

## 5. Проверки (каждая запускалась отдельно)

| Проверка | Результат |
|----------|-----------|
| `git diff --check` | exit 0 |
| Пробелы в концах строк в 30 неотслеживаемых файлах | нет |
| `npm run typecheck` | OK |
| `npm run lint` | OK |
| `npm test` | OK, 139 тестов |
| `npm run build` | OK |
| Секреты, `out/` после сборки | чисто |
| PHP runtime | не проверялся — интерпретатора нет |

---

## 6. Рекомендация

Если разбиение устраивает, следующий шаг — проиндексировать и закоммитить в порядке 1 → 2 → 3 (или с отдельным коммитом для отчётов 2.2.2 и 3.1). Перед каждым коммитом — `git diff --cached --check`. Push — только по отдельной команде.
