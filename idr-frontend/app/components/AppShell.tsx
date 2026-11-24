"use client";

import { FormEvent, ReactNode, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import AIAssistantPanel from "./AIAssistantPanel";
import { useAuth } from "./AuthProvider";
import Icon from "./Icon";

const primaryNavigation = [
  { label: "Home", href: "/" },
  { label: "Upload", href: "/upload" },
  { label: "Chat", href: "/chat" },
] as const;

export default function AppShell({ children }: { children: ReactNode }) {
  const { user, loading, signOut } = useAuth();
  const [authError, setAuthError] = useState("");
  const pathname = usePathname();
  const router = useRouter();
  const isAuthPage = pathname === "/login" || pathname === "/signup";
  const [search, setSearch] = useState("");

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    router.push(search.trim() ? `/?q=${encodeURIComponent(search.trim())}` : "/");
  };

  return (
    <div className={`app-shell${pathname === "/" ? " app-shell-home" : ""}${pathname === "/upload" || pathname === "/chat" ? " app-shell-library" : ""}${isAuthPage ? " app-shell-auth" : ""}`}>
      <header className="topbar">
        <Link aria-label="AUN Digital Repository home" className="brand" href="/">
          <Image alt="American University of Nigeria" className="brand-logo" height={124} priority src="/aun-wordmark.png" width={344} />
        </Link>
        <nav aria-label="Main navigation" className="nav-list">
          {isAuthPage ? (
            <>
              <Link className="nav-link" href="/">
                <span>Home</span>
              </Link>
              <Link aria-current={pathname === "/login" ? "page" : undefined} className={`nav-link${pathname === "/login" ? " active" : ""}`} href="/login">
                <span>Log In</span>
              </Link>
              <Link aria-current={pathname === "/signup" ? "page" : undefined} className={`nav-link${pathname === "/signup" ? " active" : ""}`} href="/signup">
                <span>Sign Up</span>
              </Link>
            </>
          ) : primaryNavigation.map((item) => {
            const active = item.label === "Home" ? pathname === "/" : pathname === item.href;
            return (
              <Link
                aria-current={active ? "page" : undefined}
                className={`nav-link${active ? " active" : ""}`}
                href={item.href}
                key={item.label}
              >
                <span>{item.label}</span>
              </Link>
            );
          })}
          {user?.role === "admin" && <Link className={`nav-link${pathname === "/admin" ? " active" : ""}`} href="/admin">Admin</Link>}
        </nav>
        {!isAuthPage && pathname !== "/" && (
          <form className="top-search" onSubmit={submitSearch} role="search">
            <Icon name="search" size={17} />
            <input
              aria-label="Search university resources"
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search documents, courses, authors, or keywords..."
              value={search}
            />
          </form>
        )}
        {!isAuthPage && (
          <div className="session-controls">{loading ? <span>Checking session…</span> : user ? <><span>{user.fullName}</span><button onClick={async () => { try { await signOut(); router.push("/"); } catch { setAuthError("Sign out failed. Please retry."); } }}>Sign out</button></> : <Link href="/login">Log In</Link>}</div>
        )}
      </header>
      {authError && <p role="alert">{authError}</p>}
      <div className="main-area">
        <div className={`workspace-grid${(pathname === "/chat" || pathname === "/upload") ? " workspace-grid-chat" : ""}${pathname === "/" ? " workspace-grid-home" : ""}${isAuthPage || pathname === "/admin" || pathname.endsWith("/certificate") ? " workspace-grid-auth" : ""}`}>
          <main className="page-content">{children}</main>
          {!isAuthPage && pathname !== "/" && pathname !== "/chat" && pathname !== "/admin" && pathname !== "/upload" && !pathname.endsWith("/certificate") && <AIAssistantPanel />}
        </div>
      </div>
    </div>
  );
}