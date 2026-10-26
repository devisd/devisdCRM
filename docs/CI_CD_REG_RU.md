# CI/CD Devis CRM: GitHub Actions → REG.RU (FTPS, без SSH)

Порядок первого релиза и все действия с БД — в [RELEASE_REG_RU.md](RELEASE_REG_RU.md).
Деплой ни разу не запускался: работоспособность подтверждается только первым ручным запуском.

## Что автоматизировано, а что нет

| Операция | Как |
|---|---|
| Тесты PHP API (PHP 8.3 + pdo_sqlite), frontend-тесты, typecheck, lint, сборка, комплект `release/reg-ru` | автоматически, workflow **CI** |
| Загрузка файлов на хостинг | вручную запускаемый workflow **Deploy to REG.RU** |
| Миграция `002_auth` | runner `devis-crm/api/bin/migrate-002-auth.php` (загружается деплоем `api`), запуск — задачей планировщика ISPmanager (RELEASE_REG_RU.md, 3a) или импортом в phpMyAdmin |
| Создание владельца, `local.php`, права, лимиты PHP/nginx | только вручную (ISPmanager / phpMyAdmin) |
| Импорт резервной копии | вручную, отдельно (RELEASE_REG_RU.md, раздел 4) |

## CI — `.github/workflows/ci.yml`

Запуск: каждый pull request, push в `feature/online-migration`, вручную (Run workflow).

1. `api-tests`: `php api/tests/run.php`; CI падает, если хоть один тест пропущен (значит, нет pdo_sqlite).
2. `release` (только после `api-tests`): `npm ci` → `npm run test` → `npm run typecheck` → `npm run lint` → `npm run build` →
   `node scripts/assemble-release.mjs` → `node scripts/verify-release.mjs release/reg-ru`.
3. Artifact **`reg-ru-release`** (30 дней): содержимое `release/reg-ru/` — `www/`, `devis-crm/`, `MANIFEST.txt`.
   MySQL в CI не используется: SQL проверяется на SQLite.

Скачать artifact: GitHub → Actions → CI → нужный запуск → Artifacts → `reg-ru-release`.
Ручная выкладка из него — по RELEASE_REG_RU.md, раздел 3.

## Deploy — `.github/workflows/deploy-reg-ru.yml`

Запуск: Actions → **Deploy to REG.RU** → Run workflow → выбрать ветку → параметры:

| Параметр | Значение |
|---|---|
| `target` | `api` — приватный код `devis-crm/api/src/` + `www/crm.devisd.online/api/{index.php,.htaccess}`; `frontend` — статический сайт; `all` — оба |
| `ci_run_id` | ID успешного запуска CI (из адреса страницы запуска); пусто — последний успешный push-запуск CI этой ветки |
| `confirm` | `crm.devisd.online` |

Что делает:
1. Проверяет подтверждение и наличие секретов; без них ничего не загружает.
2. Берёт artifact `reg-ru-release` только из успешного запуска CI этого репозитория (push или ручной запуск, не из PR) и сверяет файлы с `MANIFEST.txt`.
3. Скачивает с хостинга файлы, которые будут заменены, в artifact **`reg-ru-backup-<номер запуска>`** (30 дней).
4. Загружает через lftp (FTPS) в фиксированные пути: `devis-crm/api/src/`, `www/crm.devisd.online/api/`, `www/crm.devisd.online/` (без `api/`).
   Ничего не удаляет на сервере. `devis-crm/config/` и `devis-crm/logs/` не затрагиваются.
   Каждый файл загружается под временным именем и переименовывается — файл заменяется целиком,
   но релиз не переключается атомарно: несколько секунд на сервере одновременно старые и новые файлы. Деплоить в спокойное время.
5. Проверяет сайт: для `api` — `/api/health` = 200 с `"db":true` и `/api/auth/me` = 401;
   для `frontend` — `/` отдаёт тот же `index.html`, что в комплекте. Итог — в Summary запуска.

Успешный деплой = зелёный запуск с тремя проверками в Summary. Красный запуск после шага загрузки означает, что файлы могли обновиться частично — повторить запуск или откатить.

Порядок для первого релиза (подробно в RELEASE_REG_RU.md): дамп БД → `002_auth.sql` вручную → Deploy `api` → владелец вручную → Deploy `frontend`.
Деплой `api` до применения `002_auth.sql` сломает вход (таблиц авторизации ещё нет), хотя `/api/health` будет отвечать 200.

### Повтор и откат

- Повтор: тот же запуск можно перезапустить (Re-run) или запустить заново с тем же `ci_run_id` — загрузка идемпотентна.
- Откат на прошлую сборку: Deploy с `ci_run_id` предыдущего успешного CI.
- Откат на состояние до деплоя (в т.ч. до первого): скачать artifact `reg-ru-backup-<номер>` и загрузить его каталоги
  в те же пути через файловый менеджер ISPmanager. Файлы, которых не было в старой версии, остаются на сервере и не мешают работе.
- БД откатывается только дампом, вручную.

## Настройка GitHub (один раз)

Settings → Environments → **New environment** `production`:
- Required reviewers — вы (каждый деплой ждёт вашего подтверждения);
- Deployment branches — только `feature/online-migration` (позже — основная ветка).

Secrets окружения `production` (Settings → Environments → production → Environment secrets):

| Secret | Значение |
|---|---|
| `FTP_HOST` | имя FTP-сервера из ISPmanager/письма REG.RU; для FTPS — то имя, на которое выдан сертификат сервера |
| `FTP_USERNAME` | FTP-пользователь |
| `FTP_PASSWORD` | его пароль |

Variables окружения `production` (необязательные):

| Variable | По умолчанию | Когда менять |
|---|---|---|
| `FTP_PROTOCOL` | `ftps` | `ftp` — только если сервер не поддерживает TLS (пароль пойдёт открытым текстом) |
| `FTP_PORT` | `21` | если в ISPmanager указан другой порт |
| `FTP_REMOTE_ROOT` | пусто | если корень FTP не домашний каталог (сейчас корень `/` = домашний каталог) |

Минимальные права: отдельный FTP-пользователь в ISPmanager с доступом к домашнему каталогу сайта (нужны и `www/`, и `devis-crm/`),
не основной пароль панели. SFTP на REG.RU работает только при включённом SSH, поэтому не используется.
Если проверка сертификата FTPS не проходит — указать в `FTP_HOST` имя сервера из сертификата, а не отключать проверку.

## База данных

FTP не выполняет SQL; миграции из CI/CD не запускаются. Порядок для каждой миграции — вручную в phpMyAdmin:
1. Дамп БД (ISPmanager → Базы данных → «Скачать дамп»).
2. `SELECT version FROM schema_migrations ORDER BY version;` — должен совпасть с ожидаемым списком предыдущих версий
   (перед `002_auth`: только `001_init`). Иначе — остановиться.
3. Импорт одного файла миграции. Каждый файл начинается с `CREATE TABLE` без `IF NOT EXISTS` и заканчивается записью версии в
   `schema_migrations` (первичный ключ), поэтому повторный импорт останавливается на первом операторе.
4. Повторить шаг 2: добавилась ровно новая версия.

Автоматизировать миграции без SSH можно только через удалённый доступ к MySQL с runner'ов GitHub (их IP-адреса непостоянны —
пришлось бы открыть MySQL наружу) или через HTTP-эндпоинт, выполняющий SQL (новая поверхность атаки). Пока не делаем.
