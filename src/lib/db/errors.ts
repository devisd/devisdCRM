export class DbError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = "DbError";
  }
}

export function toUserDbMessage(error: unknown): string {
  if (error instanceof DbError) {
    return error.message;
  }

  if (error instanceof Error) {
    if (error.name === "OpenFailedError" || /indexeddb/i.test(error.message)) {
      return "Не удалось открыть локальную базу данных.";
    }
    return error.message;
  }

  return "Произошла ошибка при работе с локальной базой данных.";
}
