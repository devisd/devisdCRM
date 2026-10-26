import styles from "./AuthScreens.module.scss";

export function AuthBrand() {
  return (
    <div className={styles.brand}>
      <span className={styles.brandMark} aria-hidden>
        DS
      </span>
      <div className={styles.brandText}>
        <span className={styles.brandName}>Devis Studio</span>
        <span className={styles.brandSub}>Учёт заказов</span>
      </div>
    </div>
  );
}
