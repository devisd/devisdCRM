# Релиз Devis CRM на REG.RU: серверная часть

Инструкция, а не разрешение: шаги с пометкой **[подтверждение]** выполняются только после отдельного согласия владельца.
Шаги без пометки — только чтение и проверки (ничего не меняют на хостинге и в БД).
Загрузку файлов (шаги 3–4 и 7) можно выполнить workflow «Deploy to REG.RU» вместо файлового менеджера — см. [CI_CD_REG_RU.md](CI_CD_REG_RU.md).
Импорт резервной копии на настоящем MySQL 8 ещё не проверялся (только SQLite).

## 0. Что уже есть на хостинге

| Путь (от домашнего каталога, FTP `/`) | Состояние |
|---|---|
| `www/crm.devisd.online/api/index.php`, `.htaccess` | выложены ранее, `/api/health` отвечал 200 |
| `devis-crm/api/src/` | старая версия (до авторизации) |
| `devis-crm/config/local.php` | создан владельцем, **не трогать** |
| `devis-crm/logs/api.log` | создаётся API |
| БД CRM | `001_init` применена, `002_auth` — нет, владельца нет |

## 1. Сборка комплекта на ноутбуке

```powershell
npm run build
node scripts/assemble-release.mjs
```

Результат — `release/reg-ru/` (не попадает в git), структура повторяет домашний каталог хостинга:

| Локально | Куда на хостинге | Что это |
|---|---|---|
| `release/reg-ru/www/crm.devisd.online/api/index.php`, `.htaccess` | `www/crm.devisd.online/api/` | публичная точка входа API |
| `release/reg-ru/devis-crm/api/src/` (42 файла) | `devis-crm/api/src/` | приватный код API |
| `release/reg-ru/www/crm.devisd.online/` (остальное) | `www/crm.devisd.online/` | статический фронтенд |
| `release/reg-ru/MANIFEST.txt` | никуда | размеры и SHA-256 для сверки |

Никогда не загружаются: `config/local.php`, `api/bin/`, `api/tests/`, `api/migrations/`, `tools/`, `.env*`, исходники `src/`.
Миграции применяются через phpMyAdmin из локальных файлов `api/migrations/`.

## 2. Требования хостинга (проверить один раз в ISPmanager)

- PHP 8.3 для сайта `crm.devisd.online`; расширения: PDO, pdo_mysql (были подтверждены диагностикой), json, pcre, date — входят в ядро.
  Argon2 не нужен: владелец создаётся с bcrypt (`--bcrypt`).
- HTTPS включён (cookie `__Host-devis_session` с `Secure` работает только по HTTPS).
- **[подтверждение]** Права (ISPmanager → Файловый менеджер → Атрибуты):
  `devis-crm/config/` — 700, `devis-crm/config/local.php` — 600, `devis-crm/logs/` — 700.
  Сразу после изменения прав открыть `https://crm.devisd.online/api/health`; при ответе 503 `configuration_error` вернуть прежние права.
- Размер запроса импорта: API принимает файл копии до 8 МБ. Если файл копии меньше 1 МБ — ничего не менять.
  Если больше — **[подтверждение]**: в настройках PHP сайта `post_max_size` и `memory_limit` должны быть больше размера файла (с запасом ×10 для `memory_limit`),
  а в настройках сайта — максимальный размер тела запроса nginx.

## 3. Порядок выкладки

1. **[подтверждение] Резервная копия БД.** ISPmanager → Базы данных → БД CRM → «Скачать дамп» (или phpMyAdmin → Экспорт → SQL). Файл хранить локально, не на сайте.
2. **[подтверждение] Миграция `002_auth.sql`:**
   1. phpMyAdmin → выбрать БД CRM (имя из `local.php`) → вкладка SQL:
      `SELECT version FROM schema_migrations;` → должна быть ровно одна строка `001_init`.
      `SHOW TABLES;` → `app_meta, clients, order_items, orders, payments, schema_migrations`; таблицы `users` нет.
      Если результат другой — остановиться.
   2. phpMyAdmin → Импорт → файл `api/migrations/002_auth.sql`, кодировка UTF-8 → Вперёд.
      Повторный импорт невозможен: файл падает на первом `CREATE TABLE`, ничего не меняя.
   3. Проверка: `SELECT version FROM schema_migrations;` → `001_init`, `002_auth`; появились `users`, `sessions`, `login_attempts`.
   `001_init.sql` повторно не применять, таблицы не очищать.
3. **[подтверждение] Приватный код.** ISPmanager → Файловый менеджер → `devis-crm/api/`: загрузить содержимое
   `release/reg-ru/devis-crm/api/src/` в `devis-crm/api/src/` с заменой (подкаталоги `Auth`, `Clients`, `Controllers`, `Exceptions`,
   `Http`, `Import`, `Logging`, `Orders`, `Validation` + файлы корня). `config/` и `logs/` не трогать.
4. **[подтверждение] Публичная точка входа.** `release/reg-ru/www/crm.devisd.online/api/index.php` и `.htaccess` →
   `www/crm.devisd.online/api/` с заменой.
5. Проверка: `https://crm.devisd.online/api/health` → 200, `{"ok":true,"data":{"status":"ok","db":true}}`;
   `https://crm.devisd.online/api/auth/me` → 401 `unauthorized`. При 5xx — см. `devis-crm/logs/api.log` (без паролей и SQL).
6. **[подтверждение] Владелец.** На ноутбуке в обычном окне PowerShell (интерактивный терминал):
   ```powershell
   & "$env:LOCALAPPDATA\DevisTools\PHP83\php.exe" api\bin\hash-owner-password.php --bcrypt
   ```
   Ввести логин, пароль (скрыто) и повтор. Скопировать **только** строку `INSERT INTO users …` →
   phpMyAdmin → БД CRM → SQL → Вперёд. Вывод не сохранять в файлы, окно после этого закрыть.
   Пароль не передаётся аргументом, не вводится в phpMyAdmin и не попадает в историю команд.
7. **[подтверждение] Фронтенд.** Загрузить содержимое `release/reg-ru/www/crm.devisd.online/` (кроме `api/`, он уже загружен)
   в `www/crm.devisd.online/` с заменой. Выкладывать только после шагов 5–6: интерфейс без работающего входа не откроется.
   Интерфейс пока хранит данные в IndexedDB браузера; данные, введённые на этом домене до переключения на API, на сервер не попадут.
8. Проверка входа: открыть `https://crm.devisd.online/` → экран входа → войти; затем открыть
   `https://crm.devisd.online/api/auth/me` → 200 с вашим логином. Выход — кнопка «Выйти».

Откат: шаги 3–4 — загрузить предыдущие файлы; шаг 2 — восстановить дамп из шага 1 (только по отдельному решению).

## 4. Готовность к импорту резервной копии

Условия: шаги 1–8 выполнены, вход работает. Импорт — отдельное действие.

1. В браузере, где лежат настоящие данные, CRM → Настройки → «Экспорт JSON». Использовать именно этот файл.
2. **Предварительная проверка** (ничего не записывает). На `https://crm.devisd.online/` после входа:
   F12 → Console → вставить и выполнить, затем нажать на поле выбора файла в левом верхнем углу страницы и выбрать копию:
   ```js
   (() => {
     const input = Object.assign(document.createElement("input"), { type: "file", accept: ".json,application/json" });
     input.style.cssText = "position:fixed;top:12px;left:12px;z-index:2147483647;background:#fff;padding:8px";
     input.onchange = async () => {
       const body = await input.files[0].text();
       const me = await fetch("/api/auth/me").then((r) => r.json());
       if (!me.ok) { console.error("Нет сессии: войдите в CRM"); return; }
       const response = await fetch("/api/import/preview", {
         method: "POST",
         headers: { "Content-Type": "application/json", "X-CSRF-Token": me.data.csrfToken },
         body,
       });
       console.log(response.status, await response.json());
       input.remove();
     };
     document.body.append(input);
   })();
   ```
3. Изучить ответ (`data`): `valid` и `canImport` должны быть `true`, `errors` пустой; `counts` совпадают с ожидаемыми;
   `nextOrderNumber` — следующий номер заказа; `moneyChanges` — заказы, чьи итоги изменятся из-за пересчёта
   (`totalBefore` → `totalAfter`, по строкам `items`). Ошибки содержат только путь к записи, без значений полей.
4. **[подтверждение] Импорт** — тот же код, но `"/api/import"` вместо `"/api/import/preview"`, тот же файл.
   Ответ 201 `{"import": {...}}` означает, что всё записано одной транзакцией. Повторный импорт отклоняется (409 `import_already_done`).
   Перед импортом — свежий дамп БД (шаг 3.1). Это будет первый импорт на MySQL 8.
