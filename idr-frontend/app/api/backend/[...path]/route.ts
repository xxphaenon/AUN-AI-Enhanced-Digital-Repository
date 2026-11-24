/**
 * Same-origin proxy to the Laravel API (idr-combined).
 *
 *   browser  →  /api/backend/<path>  →  ${IDR_API_URL}/api/<path>
 *
 * Why a proxy: Laravel issues a Sanctum bearer token at login. Keeping it in an
 * HttpOnly cookie (scoped to /api/backend) means page JavaScript can never read
 * it, and the browser never talks to Laravel directly, so no CORS setup is needed.
 *
 *   - login / register: the token in the response is moved into the cookie and
 *     removed from the JSON the browser receives
 *   - every other call: the cookie is turned back into `Authorization: Bearer …`
 *   - logout, or any 401 from Laravel: the cookie is cleared
 *   - PDFs (preview / download) are streamed through unchanged
 *
 * Only standard Request/Response are used, so this file also runs in plain Node
 * for testing.
 */

const BACKEND = (process.env.IDR_API_URL || "http://127.0.0.1:8000").replace(/\/+$/, "");
const COOKIE = "idr_session";
const COOKIE_PATH = "/api/backend";

/** Response headers worth passing back to the browser. */
const PASS_HEADERS = ["content-type", "content-disposition", "cache-control", "retry-after", "x-ratelimit-remaining"];

type Context = { params: Promise<{ path?: string[] }> };

function json(status: number, body: unknown, extraHeaders: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json", ...extraHeaders } });
}

function readCookie(request: Request, name: string): string | null {
  for (const part of (request.headers.get("cookie") || "").split(";")) {
    const [key, ...rest] = part.trim().split("=");
    if (key === name) return decodeURIComponent(rest.join("="));
  }
  return null;
}

function sessionCookie(request: Request, token: string, expiresAt?: string): string {
  const maxAge = expiresAt ? Math.max(0, Math.floor((Date.parse(expiresAt) - Date.now()) / 1000)) : 60 * 60 * 24 * 7;
  const secure = new URL(request.url).protocol === "https:" ? "; Secure" : "";
  return `${COOKIE}=${encodeURIComponent(token)}; Path=${COOKIE_PATH}; HttpOnly; SameSite=Lax; Max-Age=${maxAge}${secure}`;
}

function clearedCookie(): string {
  return `${COOKIE}=; Path=${COOKIE_PATH}; HttpOnly; SameSite=Lax; Max-Age=0`;
}

/** Blocks cross-site form posts / fetches from using a student's session (CSRF). */
function isCrossSite(request: Request): boolean {
  if (request.method === "GET" || request.method === "HEAD") return false;
  if (request.headers.get("sec-fetch-site") === "cross-site") return true;
  const origin = request.headers.get("origin");
  if (!origin) return false; // same-origin fetches from older browsers; SameSite=Lax still applies
  const host = request.headers.get("x-forwarded-host") || request.headers.get("host") || new URL(request.url).host;
  try {
    return new URL(origin).host !== host;
  } catch {
    return true;
  }
}

async function proxy(request: Request, context: Context): Promise<Response> {
  const segments = (await context.params).path ?? [];

  if (segments.length === 0 || segments.some(s => s === "" || s === "." || s === ".." || s.includes("/") || s.includes("\\"))) {
    return json(404, { status: "error", message: "Not found." });
  }
  if (isCrossSite(request)) {
    return json(403, { status: "error", message: "Cross-site request blocked." });
  }

  const path = segments.join("/");
  const isLogin = path === "auth/login" || path === "auth/register";
  const isLogout = path === "auth/logout";
  const token = readCookie(request, COOKIE);

  const headers = new Headers({ accept: request.headers.get("accept") || "application/json" });
  const contentType = request.headers.get("content-type");
  if (contentType) headers.set("content-type", contentType);
  if (token) headers.set("authorization", `Bearer ${token}`);
  // Lets Laravel rate-limit per student instead of per Next.js server (it trusts only 127.0.0.1).
  const clientIp = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() || request.headers.get("x-real-ip");
  if (clientIp) headers.set("x-forwarded-for", clientIp);

  const hasBody = !["GET", "HEAD"].includes(request.method);
  const target = `${BACKEND}/api/${segments.map(encodeURIComponent).join("/")}${new URL(request.url).search}`;

  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method: request.method,
      headers,
      body: hasBody ? await request.arrayBuffer() : undefined,
      redirect: "manual",
      cache: "no-store",
    });
  } catch {
    return json(502, {
      status: "error",
      message: "The repository server is not reachable. Is `php artisan serve` running?",
    });
  }

  const outHeaders = new Headers();
  for (const name of PASS_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) outHeaders.set(name, value);
  }

  // Login / register: keep the token server-side in the HttpOnly cookie.
  if (isLogin && upstream.ok) {
    const body = await upstream.json().catch(() => null);
    const data = body?.data;
    if (data?.token) {
      outHeaders.append("set-cookie", sessionCookie(request, String(data.token), data.expiresAt));
      delete data.token;
      delete data.tokenType;
    }
    outHeaders.set("content-type", "application/json");
    return new Response(JSON.stringify(body), { status: upstream.status, headers: outHeaders });
  }

  if (isLogout || (upstream.status === 401 && token)) {
    outHeaders.append("set-cookie", clearedCookie());
  }

  // A logout with an already-expired token is still a successful sign-out.
  if (isLogout && upstream.status === 401) {
    return json(200, { status: "success", message: "Logged out." }, { "set-cookie": clearedCookie() });
  }

  return new Response(upstream.status === 204 ? null : upstream.body, { status: upstream.status, headers: outHeaders });
}

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
