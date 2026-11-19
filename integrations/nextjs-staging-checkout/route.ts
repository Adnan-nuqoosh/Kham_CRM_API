/**
 * COPY INTO THE KHAM Next.js storefront as:
 *   src/app/api/test-checkout/route.ts
 *
 * Do NOT mount this in a production storefront.
 * Next.js server runtime only; the local staging API must be bound to 127.0.0.1.
 * Set KHAM_STAGING_CHECKOUT_KEY as a strong server-only environment secret.
 */
import { createHash, timingSafeEqual } from "node:crypto";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

const STAGING_ORIGIN = "https://staging.khambeauty.com";
const UPSTREAM_URL = "http://127.0.0.1:8101/api/v1/storefront/checkout/test-order";

function secureMatch(supplied: string, secret: string): boolean {
  const a = createHash("sha256").update(supplied).digest();
  const b = createHash("sha256").update(secret).digest();
  return timingSafeEqual(a, b);
}

function reply(message: string, status: number): Response {
  return Response.json(
    { success: false, code: "STAGING_CHECKOUT_ERROR", message },
    { status, headers: { "Cache-Control": "no-store" } }
  );
}

export async function POST(request: Request): Promise<Response> {
  const secret = process.env.KHAM_STAGING_CHECKOUT_KEY || "";
  if (secret.length < 48) {
    return reply("Staging checkout is not configured.", 503);
  }
  const origin = request.headers.get("origin");
  if (origin !== STAGING_ORIGIN) {
    return reply("Invalid checkout origin.", 403);
  }
  // Host check reduces accidental exposure through a second hostname or
  // misconfigured reverse proxy. Do not trust forwarded Host headers.
  if (request.headers.get("host") !== "staging.khambeauty.com") {
    return reply("Invalid checkout host.", 403);
  }
  const supplied = request.headers.get("x-kham-test-key") || "";
  if (!secureMatch(supplied, secret)) {
    return reply("Invalid staging checkout key.", 401);
  }

  let payload: Record<string, unknown>;
  try {
    const body = await request.text();
    if (body.length > 16000) return reply("Checkout request too large.", 413);
    const parsed: unknown = JSON.parse(body);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return reply("Invalid checkout request.", 422);
    }
    payload = parsed as Record<string, unknown>;
  } catch {
    return reply("Invalid JSON payload.", 400);
  }

  // Fail closed on fields controlled exclusively by the staging backend.
  for (const forbidden of [
    "unit_price", "shipping_total", "tax_total", "customer_id",
    "warehouse_id", "payment_status", "coupon_code"
  ]) {
    if (Object.prototype.hasOwnProperty.call(payload, forbidden)) {
      return reply("Client supplied a forbidden checkout field.", 422);
    }
  }
  if (payload.payment_method !== undefined && payload.payment_method !== "mock_fatoora") {
    return reply("Only mock payments are accepted.", 422);
  }
  try {
    const upstream = await fetch(UPSTREAM_URL, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
      },
      body: JSON.stringify({ ...payload, payment_method: "mock_fatoora" }),
      cache: "no-store",
      signal: AbortSignal.timeout(15000),
    });
    const responseText = await upstream.text();
    return new Response(responseText, {
      status: upstream.status,
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "no-store",
        "X-Content-Type-Options": "nosniff",
      },
    });
  } catch {
    return reply("Staging checkout API is unavailable.", 502);
  }
}
