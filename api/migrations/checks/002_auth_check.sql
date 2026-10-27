-- Devis Studio CRM — read-only check of migration 002_auth (MySQL 8).
--
-- phpMyAdmin: select the CRM database → tab SQL → paste this file → Go.
-- One SELECT over information_schema and schema_migrations: writes nothing and reads no
-- user data, passwords or tokens. Run it before and after importing 002_auth.sql.
--
-- Row "state":
--   NOT_APPLIED — no auth tables, 002_auth not recorded: 002_auth.sql may be imported;
--   APPLIED     — all three tables exist and 002_auth is recorded: every row must be OK;
--   PARTIAL     — anything else (e.g. an interrupted import): stop, do not import again.
-- Column rows: data_type|max_length|datetime_precision|nullable|collation|unsigned|extra

SELECT check_name, expected, actual,
       CASE WHEN CAST(LOWER(actual) AS BINARY) <=> CAST(LOWER(expected) AS BINARY) THEN 'OK' ELSE 'MISMATCH' END AS status
FROM (
  SELECT 1 AS n, 'state' AS check_name, 'APPLIED' AS expected,
         CASE
           WHEN (SELECT COUNT(*) FROM schema_migrations WHERE version = '002_auth') = 1
             AND (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'sessions', 'login_attempts')) = 3
             THEN 'APPLIED'
           WHEN (SELECT COUNT(*) FROM schema_migrations WHERE version = '002_auth') = 0
             AND (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'sessions', 'login_attempts')) = 0
             THEN 'NOT_APPLIED'
           ELSE 'PARTIAL'
         END AS actual
  UNION ALL SELECT 2, 'migration 001_init recorded', 'yes',
         IF((SELECT COUNT(*) FROM schema_migrations WHERE version = '001_init') = 1, 'yes', 'no')
  UNION ALL SELECT 3, 'migration 002_auth recorded', 'yes',
         IF((SELECT COUNT(*) FROM schema_migrations WHERE version = '002_auth') = 1, 'yes', 'no')
  UNION ALL SELECT 4, 'InnoDB tables users/sessions/login_attempts', '3',
         (SELECT CAST(COUNT(*) AS CHAR) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'sessions', 'login_attempts') AND ENGINE = 'InnoDB')

  UNION ALL SELECT 10, 'users.id', 'char|36||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id')
  UNION ALL SELECT 11, 'users.login', 'varchar|64||NO|utf8mb4_unicode_ci||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'login')
  UNION ALL SELECT 12, 'users.password_hash', 'varchar|255||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash')
  UNION ALL SELECT 13, 'users.created_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'created_at')
  UNION ALL SELECT 14, 'users.updated_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'updated_at')

  UNION ALL SELECT 20, 'sessions.id', 'char|36||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'id')
  UNION ALL SELECT 21, 'sessions.user_id', 'char|36||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'user_id')
  UNION ALL SELECT 22, 'sessions.token_hash', 'char|64||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'token_hash')
  UNION ALL SELECT 23, 'sessions.csrf_token', 'char|64||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'csrf_token')
  UNION ALL SELECT 24, 'sessions.created_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'created_at')
  UNION ALL SELECT 25, 'sessions.last_seen_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'last_seen_at')
  UNION ALL SELECT 26, 'sessions.expires_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'expires_at')

  UNION ALL SELECT 30, 'login_attempts.id', 'bigint|||NO||unsigned|auto_increment',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'id')
  UNION ALL SELECT 31, 'login_attempts.login_normalized', 'varchar|64||NO|utf8mb4_unicode_ci||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'login_normalized')
  UNION ALL SELECT 32, 'login_attempts.ip_address', 'varchar|45||NO|ascii_bin||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'ip_address')
  UNION ALL SELECT 33, 'login_attempts.attempted_at', 'datetime||3|NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'attempted_at')
  UNION ALL SELECT 34, 'login_attempts.succeeded', 'tinyint|||NO|||',
         (SELECT CONCAT(DATA_TYPE, '|', COALESCE(CHARACTER_MAXIMUM_LENGTH, ''), '|', COALESCE(DATETIME_PRECISION, ''), '|', IS_NULLABLE, '|', COALESCE(COLLATION_NAME, ''), '|', IF(COLUMN_TYPE LIKE '%unsigned%', 'unsigned', ''), '|', EXTRA)
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'succeeded')

  UNION ALL SELECT 40, 'index users.PRIMARY', 'unique(id)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'PRIMARY')
  UNION ALL SELECT 41, 'index users.uq_users_login', 'unique(login)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_login')
  UNION ALL SELECT 42, 'index sessions.PRIMARY', 'unique(id)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'PRIMARY')
  UNION ALL SELECT 43, 'index sessions.uq_sessions_token_hash', 'unique(token_hash)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'uq_sessions_token_hash')
  UNION ALL SELECT 44, 'index sessions.idx_sessions_user', 'index(user_id)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'idx_sessions_user')
  UNION ALL SELECT 45, 'index sessions.idx_sessions_expires_at', 'index(expires_at)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'idx_sessions_expires_at')
  UNION ALL SELECT 46, 'index login_attempts.PRIMARY', 'unique(id)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'PRIMARY')
  UNION ALL SELECT 47, 'index login_attempts.idx_login_attempts_login_time', 'index(login_normalized,attempted_at)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_login_time')
  UNION ALL SELECT 48, 'index login_attempts.idx_login_attempts_ip_time', 'index(ip_address,attempted_at)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_ip_time')
  UNION ALL SELECT 49, 'index login_attempts.idx_login_attempts_time', 'index(attempted_at)',
         (SELECT CONCAT(IF(MIN(NON_UNIQUE) = 0, 'unique', 'index'), '(', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), ')')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_time')

  UNION ALL SELECT 60, 'foreign key fk_sessions_user', 'sessions.user_id->users.id on delete cascade',
         (SELECT CONCAT(k.TABLE_NAME, '.', k.COLUMN_NAME, '->', k.REFERENCED_TABLE_NAME, '.', k.REFERENCED_COLUMN_NAME, ' on delete ', r.DELETE_RULE)
          FROM information_schema.KEY_COLUMN_USAGE k
          JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.CONSTRAINT_NAME = 'fk_sessions_user')
  -- CHECK constraints are listed (and enforced) from MySQL 8.0.16 on.
  UNION ALL SELECT 61, 'CHECK constraints on the three tables', '6',
         (SELECT CAST(COUNT(*) AS CHAR) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'sessions', 'login_attempts') AND CONSTRAINT_TYPE = 'CHECK')
) AS checks
ORDER BY n;
