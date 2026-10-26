"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from "react";
import {
  CHECKING_STATE,
  createAuthStore,
  startSessionCheck,
  type AuthState,
  type AuthStore,
} from "@/lib/auth/authStore";
import { LOCAL_DEV_AUTH_BYPASS } from "@/lib/auth/localDev";

const AuthContext = createContext<AuthStore | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [store] = useState(() => createAuthStore());

  useEffect(() => {
    startSessionCheck(store, LOCAL_DEV_AUTH_BYPASS);
  }, [store]);

  return <AuthContext.Provider value={store}>{children}</AuthContext.Provider>;
}

export function useAuthStore(): AuthStore {
  const store = useContext(AuthContext);
  if (store === null) {
    throw new Error("useAuthStore must be used inside AuthProvider");
  }
  return store;
}

const getServerState = (): AuthState => CHECKING_STATE;

export function useAuthState(): AuthState {
  const store = useAuthStore();
  return useSyncExternalStore(store.subscribe, store.getState, getServerState);
}
