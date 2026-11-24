import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // start-public.bat shares the dev server through a tunnel (ngrok or a
  // Cloudflare quick tunnel). Next.js blocks dev-server files for other
  // hosts unless they are listed here.
  allowedDevOrigins: ["*.ngrok-free.dev", "*.ngrok-free.app", "*.ngrok.app", "*.ngrok.dev", "*.trycloudflare.com"],
};

export default nextConfig;
