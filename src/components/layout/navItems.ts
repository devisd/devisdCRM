import {
  ClipboardList,
  LayoutDashboard,
  Settings,
  Users,
  type LucideIcon,
} from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  match: "exact" | "prefix";
};

export const NAV_ITEMS: NavItem[] = [
  {
    href: "/",
    label: "Обзор",
    icon: LayoutDashboard,
    match: "exact",
  },
  {
    href: "/orders",
    label: "Заказы",
    icon: ClipboardList,
    match: "prefix",
  },
  {
    href: "/clients",
    label: "Клиенты",
    icon: Users,
    match: "prefix",
  },
  {
    href: "/settings",
    label: "Настройки",
    icon: Settings,
    match: "prefix",
  },
];

export function isNavItemActive(pathname: string, item: NavItem): boolean {
  if (item.match === "exact") {
    return pathname === item.href;
  }
  return pathname === item.href || pathname.startsWith(`${item.href}/`);
}
