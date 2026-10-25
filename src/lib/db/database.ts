import Dexie, { type EntityTable } from "dexie";
import type { Client, Order, OrderItem, Payment } from "@/types";

export type AppMeta = {
  key: string;
  value: string | number | boolean;
};

export const DB_NAME = "devis-studio";
export const DB_VERSION = 1;

export class DevisStudioDB extends Dexie {
  clients!: EntityTable<Client, "id">;
  orders!: EntityTable<Order, "id">;
  orderItems!: EntityTable<OrderItem, "id">;
  payments!: EntityTable<Payment, "id">;
  appMeta!: EntityTable<AppMeta, "key">;

  constructor() {
    super(DB_NAME);

    this.version(DB_VERSION).stores({
      clients: "id, name, isArchived, updatedAt, createdAt",
      orders:
        "id, number, clientId, status, orderDate, dueDate, isArchived, updatedAt, createdAt, [clientId+orderDate]",
      orderItems: "id, orderId, sortOrder, [orderId+sortOrder]",
      payments: "id, orderId, paymentDate, createdAt, [orderId+paymentDate]",
      appMeta: "key",
    });
  }
}

let dbInstance: DevisStudioDB | null = null;

export function isIndexedDbAvailable(): boolean {
  try {
    return typeof indexedDB !== "undefined";
  } catch {
    return false;
  }
}

export function getDb(): DevisStudioDB {
  if (typeof window === "undefined") {
    throw new Error("База данных доступна только в браузере");
  }

  if (!isIndexedDbAvailable()) {
    throw new Error(
      "IndexedDB недоступен в этом браузере. Данные не могут быть сохранены.",
    );
  }

  if (!dbInstance) {
    dbInstance = new DevisStudioDB();
  }

  return dbInstance;
}

/** Test helper: reset singleton between tests if needed. */
export function resetDbInstance(): void {
  dbInstance = null;
}

export const META_KEYS = {
  nextOrderNumber: "nextOrderNumber",
  schemaVersion: "schemaVersion",
} as const;
