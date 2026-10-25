import type { Metadata, Viewport } from "next";
import type { ReactNode } from "react";
import { Manrope } from "next/font/google";
import { AppShell } from "@/components/layout/AppShell";
import "./globals.scss";

const manrope = Manrope({
  variable: "--font-app",
  subsets: ["latin", "cyrillic"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Devis Studio — учёт заказов",
  description:
    "Локальное приложение для учёта клиентов, заказов, работ и оплат",
  applicationName: "Devis Studio Work Tracker",
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#1f4e79",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ru" className={manrope.variable}>
      <body>
        <AppShell>{children}</AppShell>
      </body>
    </html>
  );
}
