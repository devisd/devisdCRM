/** App path helpers for static-export-friendly URLs (query ids, no dynamic segments).
 * Paths use trailing slashes to match `trailingSlash: true` in next.config.
 */

export const routes = {
  home: "/",
  clients: "/clients/",
  clientNew: "/clients/new/",
  clientDetail: (id: string) =>
    `/clients/detail/?id=${encodeURIComponent(id)}`,
  clientEdit: (id: string) => `/clients/edit/?id=${encodeURIComponent(id)}`,
  orders: "/orders/",
  orderNew: (clientId?: string) =>
    clientId
      ? `/orders/new/?clientId=${encodeURIComponent(clientId)}`
      : "/orders/new/",
  orderDetail: (id: string) => `/orders/detail/?id=${encodeURIComponent(id)}`,
  orderEdit: (id: string) => `/orders/edit/?id=${encodeURIComponent(id)}`,
  settings: "/settings/",
} as const;
