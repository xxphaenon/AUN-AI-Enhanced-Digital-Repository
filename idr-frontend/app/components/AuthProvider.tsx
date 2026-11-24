"use client";
import { createContext, useContext, useEffect, useState, useCallback, type ReactNode } from "react";
import { ApiError, getMe, logout, type User } from "../lib/api";
const Context = createContext<{ user: User | null; loading: boolean; error: string; refresh: () => Promise<User | null>; signOut: () => Promise<void> } | null>(null);
export default function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  // The session lives in an HttpOnly cookie the page cannot read, so ask the backend who we are.
  const refresh = useCallback(() => getMe()
    .then(me => { setUser(me); setError(""); return me; })
    .catch((e: unknown) => { setUser(null); setError(e instanceof ApiError && e.status === 401 ? "" : "Unable to verify your session. Please retry."); return null; })
    .finally(() => setLoading(false)), []);
  useEffect(() => {
    void refresh();
    const expire = () => setUser(null);
    // Re-check the session when the tab regains focus, at most once a minute.
    let lastCheck = Date.now();
    const focus = () => { if (Date.now() - lastCheck > 60_000) { lastCheck = Date.now(); void refresh(); } };
    window.addEventListener("session-expired", expire);
    window.addEventListener("focus", focus);
    return () => { window.removeEventListener("session-expired", expire); window.removeEventListener("focus", focus); };
  }, [refresh]);
  async function signOut() { await logout(); setUser(null); }
  return <Context.Provider value={{ user, loading, error, refresh, signOut }}>{children}</Context.Provider>;
}
export function useAuth() { const value = useContext(Context); if (!value) throw new Error("AuthProvider is required"); return value; }
