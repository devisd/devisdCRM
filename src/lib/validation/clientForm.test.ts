import { describe, expect, it } from "vitest";
import {
  clientFormSchema,
  emptyClientFormValues,
  formValuesToClientInput,
  type ClientFormValues,
} from "./clientForm";
import { maxLengthMessage, TEXT_LIMITS } from "./schemas";

function firstIssue(values: ClientFormValues, field: keyof ClientFormValues) {
  const parsed = clientFormSchema.safeParse(values);
  if (parsed.success) return undefined;
  return parsed.error.issues.find((issue) => issue.path[0] === field)?.message;
}

const base: ClientFormValues = { ...emptyClientFormValues, name: "Клиент" };

const limited: [keyof ClientFormValues, number][] = [
  ["name", TEXT_LIMITS.short],
  ["contactName", TEXT_LIMITS.short],
  ["phone", TEXT_LIMITS.short],
  ["telegram", TEXT_LIMITS.short],
  ["maxContact", TEXT_LIMITS.short],
  ["source", TEXT_LIMITS.short],
  ["otherContact", TEXT_LIMITS.long],
  ["notes", TEXT_LIMITS.long],
];

describe("clientFormSchema length limits", () => {
  it.each(limited)("%s: accepts the limit, rejects one more", (field, limit) => {
    expect(clientFormSchema.safeParse({ ...base, [field]: "ж".repeat(limit) }).success).toBe(true);
    expect(firstIssue({ ...base, [field]: "ж".repeat(limit + 1) }, field)).toBe(
      maxLengthMessage(limit),
    );
  });

  it("email: reports the length before the format", () => {
    const domain = "@example.com";
    const atLimit = `${"a".repeat(TEXT_LIMITS.short - domain.length)}${domain}`;
    expect(clientFormSchema.safeParse({ ...base, email: atLimit }).success).toBe(true);
    expect(firstIssue({ ...base, email: `a${atLimit}` }, "email")).toBe(
      maxLengthMessage(TEXT_LIMITS.short),
    );
  });

  it("keeps empty optional fields valid and maps them to undefined", () => {
    const parsed = clientFormSchema.safeParse(base);
    expect(parsed.success).toBe(true);
    if (!parsed.success) return;
    const input = formValuesToClientInput(parsed.data);
    expect(input.contactName).toBeUndefined();
    expect(input.otherContact).toBeUndefined();
    expect(input.notes).toBeUndefined();
  });

  it("keeps the required-name message", () => {
    expect(firstIssue({ ...base, name: "   " }, "name")).toBe("Укажите имя или название");
  });
});
