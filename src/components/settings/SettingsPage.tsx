"use client";

import { useRef, useState, type ChangeEvent } from "react";
import { PageHeader } from "@/components/ui/PageHeader";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ConfirmDialog } from "@/components/ui/ConfirmDialog";
import { useDbReady } from "@/lib/hooks/useDbReady";
import { toUserDbMessage } from "@/lib/db/errors";
import {
  clearAllData,
  countBackup,
  countLabel,
  downloadJsonFile,
  exportBackup,
  readBackupJson,
  replaceDatabaseWithBackup,
  seedDemoData,
  type BackupCounts,
} from "@/lib/services/backupOps";
import type { BackupRecalculation } from "@/lib/services/backup";
import { formatRubFromMinor } from "@/lib/format/currency";
import type { BackupFile } from "@/types";
import styles from "./SettingsPage.module.scss";

type PendingAction = "import" | "seed" | "clear" | null;

type PendingImport = { backup: BackupFile; recalculation: BackupRecalculation };

function recalculationWarning({ changedOrders, totalDifferenceMinor }: BackupRecalculation): string {
  if (changedOrders === 0) {
    return "";
  }
  const sign = totalDifferenceMinor > 0 ? "+" : "";
  return ` Суммы в файле рассчитаны по прежнему правилу округления: итоги ${changedOrders} заказ(ов) будут пересчитаны, общая разница ${sign}${formatRubFromMinor(totalDifferenceMinor)}. Количества, цены, скидки и оплаты не меняются.`;
}

export function SettingsPageView() {
  const db = useDbReady();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [pendingAction, setPendingAction] = useState<PendingAction>(null);
  const [pendingImport, setPendingImport] = useState<PendingImport | null>(null);
  const [importIssues, setImportIssues] = useState<string[]>([]);

  if (db.status === "loading") {
    return <LoadingState label="Загрузка настроек…" />;
  }

  if (db.status === "unavailable") {
    return <Alert tone="error" title="База недоступна">{db.message}</Alert>;
  }

  const resetMessages = () => {
    setError(null);
    setSuccess(null);
    setImportIssues([]);
  };

  const onExport = async () => {
    resetMessages();
    setBusy(true);
    try {
      const { filename, json } = await exportBackup();
      downloadJsonFile(filename, json);
      setSuccess(`Резервная копия сохранена: ${filename}`);
    } catch (err) {
      setError(toUserDbMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const onPickImportFile = () => {
    resetMessages();
    fileInputRef.current?.click();
  };

  const onImportFileChange = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) {
      return;
    }

    resetMessages();
    try {
      const result = readBackupJson(await file.text());
      if (!result.ok) {
        setImportIssues(
          result.issues.slice(0, 8).map((issue) => `${issue.path}: ${issue.message}`),
        );
        setError("Импорт отклонён: файл некорректен. Текущая база не изменена.");
        return;
      }
      setPendingImport({ backup: result.backup, recalculation: result.recalculation });
      setPendingAction("import");
    } catch {
      setError("Не удалось прочитать выбран.");
    }
  };

  const runConfirmedAction = async () => {
    if (!pendingAction) {
      return;
    }

    setBusy(true);
    resetMessages();
    try {
      if (pendingAction === "import") {
        if (!pendingImport) {
          throw new Error("Файл импорта не выбран");
        }
        const counts = await replaceDatabaseWithBackup(pendingImport.backup);
        setSuccess(`Импорт выполнен. Загружено: ${countLabel(counts)}.`);
      }

      if (pendingAction === "seed") {
        const counts: BackupCounts = await seedDemoData();
        setSuccess(`Тестовые данные созданы: ${countLabel(counts)}.`);
      }

      if (pendingAction === "clear") {
        await clearAllData();
        setSuccess("База данных очищена.");
      }
    } catch (err) {
      setError(toUserDbMessage(err));
    } finally {
      setBusy(false);
      setPendingAction(null);
      setPendingImport(null);
    }
  };

  const confirmCopy =
    pendingAction === "import"
      ? {
          title: "Заменить все данные?",
          description: `Текущие клиенты, заказы и платежи будут полностью заменены данными из файла (${pendingImport ? countLabel(countBackup(pendingImport.backup)) : ""}). Это действие нельзя отменить без другой резервной копии.${pendingImport ? recalculationWarning(pendingImport.recalculation) : ""}`,
          confirmLabel: "Импортировать и заменить",
        }
      : pendingAction === "seed"
        ? {
            title: "Создать тестовые данные?",
            description:
              "Текущая база будет заменена небольшим демо-набором (2 клиента, заказы и частичная оплата). Экспортируйте свои данные заранее, если они нужны.",
            confirmLabel: "Создать демо-данные",
          }
        : {
            title: "Очистить базу?",
            description:
              "Все локальные данные будут удалены без возможности восстановления. Сначала сделайте экспорт, если данные важны.",
            confirmLabel: "Очистить базу",
          };

  return (
    <div>
      <PageHeader
        title="Настройки"
        description="Резервные копии, тестовые данные и очистка локальной базы."
      />

      <section className={styles.notice} aria-labelledby="storage-heading">
        <h2 id="storage-heading" className={styles.sectionTitle}>
          Где хранятся данные
        </h2>
        <p className={styles.text}>
          Данные хранятся локально в браузере (IndexedDB) и не отправляются на
          сервер. Очистка данных браузера, смена профиля или потеря устройства
          могут привести к потере информации. Рекомендуем регулярно экспортировать
          резервные копии.
        </p>
      </section>

      <section className={styles.notice} aria-labelledby="offline-heading">
        <h2 id="offline-heading" className={styles.sectionTitle}>
          Работа без сети
        </h2>
        <p className={styles.text}>
          После загрузки приложения создание и изменение записей работают без
          интернета: данные пишутся в IndexedDB на этом устройстве. Повторное
          открытие вкладки без сети зависит от того, сохранил ли браузер файлы
          приложения в своём кэше. Отдельный офлайн-режим (PWA / Service Worker)
          в MVP не подключался — для надёжности держите резервные копии JSON.
        </p>
      </section>

      {error ? (
        <Alert tone="error" title="Ошибка">
          <p>{error}</p>
          {importIssues.length > 0 ? (
            <ul className={styles.issueList}>
              {importIssues.map((issue) => (
                <li key={issue}>{issue}</li>
              ))}
            </ul>
          ) : null}
        </Alert>
      ) : null}

      {success ? <Alert tone="success" title="Готово">{success}</Alert> : null}

      <section className={styles.panel} aria-labelledby="backup-heading">
        <h2 id="backup-heading" className={styles.sectionTitle}>
          Резервная копия
        </h2>
        <p className={styles.text}>
          Экспорт сохраняет JSON-файл на устройство. Импорт полностью заменяет
          текущую базу после проверки формата и связей.
        </p>
        <div className={styles.actions}>
          <Button type="button" onClick={() => void onExport()} disabled={busy}>
            Экспорт JSON
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={onPickImportFile}
            disabled={busy}
          >
            Импорт JSON
          </Button>
          <input
            ref={fileInputRef}
            type="file"
            accept="application/json,.json"
            className={styles.hiddenInput}
            onChange={(event) => void onImportFileChange(event)}
          />
        </div>
      </section>

      <section className={styles.panel} aria-labelledby="demo-heading">
        <h2 id="demo-heading" className={styles.sectionTitle}>
          Тестовые данные
        </h2>
        <p className={styles.text}>
          Создаёт небольшой демо-набор только по вашему действию. Не является
          прайсом и не подставляет цены в новые заказы.
        </p>
        <Button
          type="button"
          variant="secondary"
          disabled={busy}
          onClick={() => {
            resetMessages();
            setPendingAction("seed");
          }}
        >
          Создать тестовые данные
        </Button>
      </section>

      <section className={styles.panel} aria-labelledby="danger-heading">
        <h2 id="danger-heading" className={styles.sectionTitle}>
          Опасная зона
        </h2>
        <p className={styles.text}>
          Полная очистка локальной базы. Перед очисткой сделайте экспорт.
        </p>
        <Button
          type="button"
          variant="danger"
          disabled={busy}
          onClick={() => {
            resetMessages();
            setPendingAction("clear");
          }}
        >
          Очистить базу
        </Button>
      </section>

      <ConfirmDialog
        open={pendingAction !== null}
        title={confirmCopy.title}
        description={confirmCopy.description}
        confirmLabel={confirmCopy.confirmLabel}
        danger={pendingAction === "clear" || pendingAction === "import"}
        busy={busy}
        onCancel={() => {
          if (busy) return;
          setPendingAction(null);
          setPendingImport(null);
        }}
        onConfirm={() => void runConfirmedAction()}
      />
    </div>
  );
}
