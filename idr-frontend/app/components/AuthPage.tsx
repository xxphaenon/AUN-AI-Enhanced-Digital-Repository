"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError, AUTH_FIELD_MAP, login, register, safeReturnPath } from "../lib/api";
import { useAuth } from "./AuthProvider";
import Icon from "./Icon";

type AuthMode = "login" | "signup";

interface AuthField {
  name: string;
  label: string;
  type: "text" | "email" | "password";
  autoComplete: string;
}

const loginFields: AuthField[] = [
  { name: "email", label: "University Email", type: "email", autoComplete: "username" },
  { name: "password", label: "Password", type: "password", autoComplete: "current-password" },
];

const signupFields: AuthField[] = [
  { name: "fullName", label: "Full Name", type: "text", autoComplete: "name" },
  { name: "email", label: "University Email", type: "email", autoComplete: "email" },
  { name: "universityId", label: "Student / Staff ID", type: "text", autoComplete: "off" },
  { name: "password", label: "Password", type: "password", autoComplete: "new-password" },
  { name: "confirmPassword", label: "Confirm Password", type: "password", autoComplete: "new-password" },
];

const benefits = [
  "Search university resources",
  "Upload academic materials",
  "Ask questions with AI",
];

export default function AuthPage({ mode }: { mode: AuthMode }) {
  const router = useRouter();
  const { refresh } = useAuth();
  const [busy, setBusy] = useState(false);
  const isSignup = mode === "signup";
  const fields = isSignup ? signupFields : loginFields;
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [visiblePasswords, setVisiblePasswords] = useState<Record<string, boolean>>({});
  const [notice, setNotice] = useState("");

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (busy) return;
    const values = new FormData(event.currentTarget);
    const nextErrors: Record<string, string> = {};

    for (const field of fields) {
      if (!String(values.get(field.name) ?? "").trim()) {
        nextErrors[field.name] = `${field.label} is required.`;
      }
    }

    const submittedEmail = String(values.get("email") ?? "").trim();
    if (submittedEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(submittedEmail)) nextErrors.email = "Enter a valid email address.";
    if (isSignup) {
      const email = String(values.get("email") ?? "").trim();
      if (email && !/^[^\s@]+@aun\.edu\.ng$/i.test(email)) {
        nextErrors.email = "Use your @aun.edu.ng university email address.";
      }

      const password = String(values.get("password") ?? "");
      if (password.length < 8) nextErrors.password = "Use at least 8 characters.";
      else if (!/[a-z]/i.test(password) || !/[0-9]/.test(password)) nextErrors.password = "Use both letters and numbers.";
      const universityId = String(values.get("universityId") ?? "").trim();
      if (universityId && !/^[A-Za-z0-9/-]+$/.test(universityId)) nextErrors.universityId = "Use only letters, numbers, \"/\" and \"-\".";
      const confirmPassword = String(values.get("confirmPassword") ?? "");
      if (password && confirmPassword && password !== confirmPassword) {
        nextErrors.confirmPassword = "Passwords do not match.";
      }
    }

    setErrors(nextErrors);
    setNotice("");

    if (Object.keys(nextErrors).length === 0) {
      setBusy(true);
      try {
        const email = String(values.get("email")).trim().toLowerCase();
        const password = String(values.get("password"));
        if (isSignup) {
          await register({ email, password, fullName: String(values.get("fullName")).trim(), universityId: String(values.get("universityId")).trim(), confirmPassword: String(values.get("confirmPassword")) });
        } else {
          await login(email, password);
        }
        const user = await refresh();
        if (!user) { setNotice("Please log in to verify your session and continue."); return; }
        // Admins land on the review dashboard unless they were sent here from a specific page.
        const home = user.role === "admin" ? "/admin" : "/";
        const next = safeReturnPath(new URLSearchParams(window.location.search).get("next"), home);
        router.replace(next === "/" ? home : next);
      } catch (error) {
        if (error instanceof ApiError && error.status === 422 && Object.keys(error.errors).length > 0) {
          const fieldErrors: Record<string, string> = {};
          for (const [key, messages] of Object.entries(error.errors)) {
            const field = AUTH_FIELD_MAP[key];
            if (field && fields.some(f => f.name === field)) fieldErrors[field] = messages[0];
          }
          setErrors(fieldErrors);
          // Login failures come back on "email" ("These credentials do not match…"): show them as a notice too.
          setNotice(Object.keys(fieldErrors).length === 0 || !isSignup ? error.message : "");
        } else {
          setNotice(error instanceof Error ? error.message : "Unable to sign in. Please try again.");
        }
      }
      finally { setBusy(false); }
    }
  };

  return (
    <div className={`auth-layout${isSignup ? " auth-layout-signup" : ""}`}>
      <section aria-labelledby="auth-intro-title" className="auth-intro">
        <p className="auth-brandline"><Icon name="academic" size={19} /> AUN Digital Repository</p>
        <h1 id="auth-intro-title">Discover. Learn.<br />Share Knowledge.</h1>
        <p className="auth-intro-copy">Access senior projects, research papers, course materials and university knowledge in one place.</p>
        <ul className="auth-benefits">
          {benefits.map((benefit) => (
            <li key={benefit}><Icon name="check" size={17} /><span>{benefit}</span></li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="auth-form-title" className="auth-card">
        <header className="auth-card-header">
          <h2 id="auth-form-title">{isSignup ? "Create an Account" : "Welcome Back"}</h2>
          <p>{isSignup ? "Join the university knowledge repository." : "Log in to continue to the university knowledge repository."}</p>
        </header>

        <form className="auth-form" noValidate onSubmit={handleSubmit}>
          {fields.map((field) => {
            const fieldId = `auth-${field.name}`;
            const errorId = `${fieldId}-error`;
            const isPassword = field.type === "password";
            const isVisible = visiblePasswords[field.name] ?? false;

            return (
              <div className="auth-field" key={field.name}>
                <label htmlFor={fieldId}>{field.label}</label>
                <div className={`auth-input-wrap${isPassword ? " has-password-toggle" : ""}`}>
                  <input
                    aria-describedby={errors[field.name] ? errorId : undefined}
                    aria-invalid={Boolean(errors[field.name])}
                    autoComplete={field.autoComplete}
                    id={fieldId}
                    name={field.name}
                    type={isPassword && isVisible ? "text" : field.type}
                  />
                  {isPassword && (
                    <button
                      aria-controls={fieldId}
                      aria-label={`${isVisible ? "Hide" : "Show"} ${field.label.toLowerCase()}`}
                      aria-pressed={isVisible}
                      className="auth-password-toggle"
                      onClick={() => setVisiblePasswords((current) => ({ ...current, [field.name]: !isVisible }))}
                      type="button"
                    >
                      {isVisible ? "Hide" : "Show"}
                    </button>
                  )}
                </div>
                {errors[field.name] && <span className="auth-field-error" id={errorId}>{errors[field.name]}</span>}
              </div>
            );
          })}

          {notice && <p className="auth-notice" role="status">{notice}</p>}

          <button className="auth-submit" disabled={busy} type="submit">
            {busy ? "Please wait…" : isSignup ? "Create Account" : "Log In"}
          </button>
        </form>


        <p className="auth-switch">
          {isSignup ? "Already have an account?" : "Don't have an account?"}{" "}
          <Link href={isSignup ? "/login" : "/signup"} onClick={event => {
            event.preventDefault();
            const next = safeReturnPath(new URLSearchParams(window.location.search).get("next"));
            router.push(`${isSignup ? "/login" : "/signup"}?next=${encodeURIComponent(next)}`);
          }}>{isSignup ? "Log In" : "Sign Up"}</Link>
        </p>
      </section>
    </div>
  );
}