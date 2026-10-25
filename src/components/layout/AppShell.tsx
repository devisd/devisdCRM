import { Suspense, type ReactNode } from "react";
import { SidebarNav } from "./SidebarNav";
import styles from "./AppShell.module.scss";

type AppShellProps = {
  children: ReactNode;
};

function NavFallback() {
  return (
    <nav className={styles.navFallback} aria-label="Основная навигация">
      <div className={styles.navFallbackItem} />
      <div className={styles.navFallbackItem} />
      <div className={styles.navFallbackItem} />
      <div className={styles.navFallbackItem} />
    </nav>
  );
}

export function AppShell({ children }: AppShellProps) {
  return (
    <div className={styles.shell}>
      <a href="#main-content" className={styles.skipLink}>
        Перейти к содержимому
      </a>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          <span className={styles.brandMark} aria-hidden>
            DS
          </span>
          <div className={styles.brandText}>
            <span className={styles.brandName}>Devis Studio</span>
            <span className={styles.brandSub}>Учёт заказов</span>
          </div>
        </div>
        <Suspense fallback={<NavFallback />}>
          <SidebarNav />
        </Suspense>
      </aside>

      <div className={styles.mainColumn}>
        <header className={styles.mobileHeader}>
          <div className={styles.brandCompact}>
            <span className={styles.brandMark} aria-hidden>
              DS
            </span>
            <span className={styles.brandName}>Devis Studio</span>
          </div>
        </header>

        <div className={styles.mobileNav}>
          <Suspense fallback={<NavFallback />}>
            <SidebarNav />
          </Suspense>
        </div>

        <main id="main-content" className={styles.content} tabIndex={-1}>
          {children}
        </main>
      </div>
    </div>
  );
}
