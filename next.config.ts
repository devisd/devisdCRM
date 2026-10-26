import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "export",
  // Emit directory indexes (…/detail/index.html) so Apache/REG.RU can serve
  // /clients/detail/ without rewrite rules or .htaccess.
  trailingSlash: true,
  // cacheComponents / partialPrefetching enable PPR, which Next.js rejects in
  // export mode ("Invariant: PPR cannot be enabled in export mode").
};

export default nextConfig;
