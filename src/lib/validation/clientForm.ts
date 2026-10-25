import { z } from "zod";

const optionalText = z.string().trim();

export const clientFormSchema = z.object({
  name: z.string().trim().min(1, "Укажите имя или название"),
  contactName: optionalText,
  phone: optionalText,
  email: z
    .string()
    .trim()
    .refine(
      (value) => value === "" || z.string().email().safeParse(value).success,
      "Некорректный email",
    ),
  telegram: optionalText,
  maxContact: optionalText,
  otherContact: optionalText,
  source: optionalText,
  notes: optionalText,
});

export type ClientFormValues = z.infer<typeof clientFormSchema>;

export const emptyClientFormValues: ClientFormValues = {
  name: "",
  contactName: "",
  phone: "",
  email: "",
  telegram: "",
  maxContact: "",
  otherContact: "",
  source: "",
  notes: "",
};

export function clientToFormValues(client: {
  name: string;
  contactName?: string;
  phone?: string;
  email?: string;
  telegram?: string;
  maxContact?: string;
  otherContact?: string;
  source?: string;
  notes?: string;
}): ClientFormValues {
  return {
    name: client.name,
    contactName: client.contactName ?? "",
    phone: client.phone ?? "",
    email: client.email ?? "",
    telegram: client.telegram ?? "",
    maxContact: client.maxContact ?? "",
    otherContact: client.otherContact ?? "",
    source: client.source ?? "",
    notes: client.notes ?? "",
  };
}

export function formValuesToClientInput(values: ClientFormValues) {
  const emptyToUndefined = (value: string) => {
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
  };

  return {
    name: values.name.trim(),
    contactName: emptyToUndefined(values.contactName),
    phone: emptyToUndefined(values.phone),
    email: emptyToUndefined(values.email),
    telegram: emptyToUndefined(values.telegram),
    maxContact: emptyToUndefined(values.maxContact),
    otherContact: emptyToUndefined(values.otherContact),
    source: emptyToUndefined(values.source),
    notes: emptyToUndefined(values.notes),
  };
}
