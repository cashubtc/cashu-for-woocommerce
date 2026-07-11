// Pure, side-effect-free helpers extracted from checkout.ts for testability.
// Nothing here touches jQuery, cashu-ts wallets, or the global window.cashu_wc
// dictionary — exercising these functions only requires a localStorage-capable
// environment (jsdom in tests, the real browser at runtime).

import { sha512 } from '@noble/hashes/sha2.js';
import type { Proof } from '@cashu/cashu-ts';
import { getErrorMessage } from './utils';

// ------------------------------
// Types
// ------------------------------

export type ChangeItem = {
  mint: string;
  token: string;
  amount: number;
  kind: string;
  dust: boolean;
};

export type ChangePayload = {
  v: 1;
  created: number;
  items: ChangeItem[];
};

export type PersistedMintProofs = {
  v: 1;
  created: number;
  quote: string;
  mint: string;
  expected: number;
  proofs: Proof[];
};

// ------------------------------
// LocalStorage primitives
// ------------------------------

export function loadJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export function saveJson(key: string, val: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(val));
  } catch {
    // ignore
  }
}

export function deleteJson(key: string): void {
  try {
    localStorage.removeItem(key);
  } catch {
    // ignore
  }
}

// ------------------------------
// Change payload (thanks-page change display)
// ------------------------------

export const CHANGE_PAYLOAD_KEY = 'cashu_wc_change';
export const CHANGE_PAYLOAD_TTL_MS = 60 * 60 * 1000;
export const CHANGE_PAYLOAD_MAX_ITEMS = 5;

export function loadChangePayload(key: string): ChangePayload {
  // loadJson already catches; nothing else here throws.
  const parsed = loadJson<ChangePayload>(key);
  if (
    !parsed ||
    !Array.isArray(parsed.items) ||
    Date.now() - parsed.created > CHANGE_PAYLOAD_TTL_MS
  ) {
    return { v: 1, created: Date.now(), items: [] };
  }
  return parsed;
}

/**
 * Append a change item to the change-payload localStorage, deduplicated by
 * token and trimmed to the last CHANGE_PAYLOAD_MAX_ITEMS entries. Adding a
 * token that's already present is a no-op (no resurrection). The TTL-based
 * clear and per-order init clear are handled at the call site — this
 * helper just maintains the list.
 */
export function rememberChangeItem(item: ChangeItem): void {
  const payload = loadChangePayload(CHANGE_PAYLOAD_KEY);
  if (!payload.items.some((x) => x.token === item.token)) {
    payload.items.push(item);
  }
  payload.items = payload.items.slice(-CHANGE_PAYLOAD_MAX_ITEMS);
  saveJson(CHANGE_PAYLOAD_KEY, payload);
}

// ------------------------------
// Stranded-proof recovery (localStorage persistence)
// ------------------------------
// After mintProofsBolt11 transitions a mint quote PAID -> ISSUED, the proofs
// only exist in JS memory until meltProofsBolt11 spends them. A refresh in
// that window strands the customer: the mint has issued, the merchant has
// not been paid, and the ephemeral wallet can't re-derive the blinded
// secrets to recover (no NUT-09 seed). We persist the proofs to localStorage
// the instant mintProofsBolt11 returns and clear them after melt succeeds.
// On reload, the ISSUED branch of startMintQuoteWatcher re-enters the melt
// step instead of stranding.

export const STRANDED_KEY_PREFIX = 'cashu_wc_minted_';
// Slightly longer than the typical mint quote / merchant melt quote lifetime
// so a customer who refreshes well into a stalled flow can still recover.
export const STRANDED_TTL_MS = 24 * 60 * 60 * 1000;

export function strandedKey(quoteId: string): string {
  return STRANDED_KEY_PREFIX + quoteId;
}

export function loadStrandedProofs(quoteId: string): Proof[] | null {
  const parsed = loadJson<PersistedMintProofs>(strandedKey(quoteId));
  if (!parsed || parsed.v !== 1) return null;
  if (Date.now() - parsed.created > STRANDED_TTL_MS) {
    deleteJson(strandedKey(quoteId));
    return null;
  }
  if (parsed.quote !== quoteId) return null;
  if (!Array.isArray(parsed.proofs) || parsed.proofs.length === 0) return null;
  return parsed.proofs;
}

export function saveStrandedProofs(
  quoteId: string,
  mint: string,
  expected: number,
  proofs: Proof[],
): void {
  if (!Array.isArray(proofs) || proofs.length === 0) return;
  saveJson(strandedKey(quoteId), {
    v: 1,
    created: Date.now(),
    quote: quoteId,
    mint,
    expected,
    proofs,
  } satisfies PersistedMintProofs);
}

export function clearStrandedProofs(quoteId: string): void {
  deleteJson(strandedKey(quoteId));
}

// Bounded sweep of localStorage for stranded-proof entries past TTL. Called
// once at init so abandoned-order keys don't accumulate over the long tail.
export function sweepStaleStrandedProofs(): void {
  try {
    const stale: string[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (!key || !key.startsWith(STRANDED_KEY_PREFIX)) continue;
      const parsed = loadJson<PersistedMintProofs>(key);
      if (!parsed || parsed.v !== 1 || Date.now() - parsed.created > STRANDED_TTL_MS) {
        stale.push(key);
      }
    }
    // The outer try covers localStorage.length / .key(i) access, which can
    // throw in security-restricted iframes. removeItem itself doesn't
    // throw on missing keys, so no per-key guard is needed.
    for (const key of stale) {
      localStorage.removeItem(key);
    }
  } catch {
    // ignore — restricted-storage context, nothing recoverable
  }
}

// ------------------------------
// Wallet seed derivation
// ------------------------------

/**
 * Deterministic 64-byte seed for the per-order cashu-ts Wallet. Recomputed
 * identically on every page load from inputs that already live in receipt-
 * page data-attrs (order_key + mint_quote_id), so a fresh browser, a
 * different device, or a wiped localStorage all derive the same seed and
 * can NUT-09-restore proofs the mint has already issued.
 *
 * The 'cashu_wc_wallet_seed_v1' domain string is versioned so a future
 * derivation-scheme change can rotate without colliding with existing
 * seeded orders.
 */
export function deriveWalletSeed(orderKey: string, mintQuoteId: string): Uint8Array {
  const input = `cashu_wc_wallet_seed_v1|${orderKey}|${mintQuoteId}`;
  return sha512(new TextEncoder().encode(input));
}

/**
 * Stable short fingerprint of a seed for use in the wallet cache key, so
 * two orders against the same mint never share a Wallet instance (different
 * seeds → different deterministic counter state → fatal counter collision).
 * First 8 seed bytes as hex — 16 hex chars, ~2^-64 collision space; plenty
 * for an in-memory per-tab cache key, not a security primitive.
 */
export function seedFingerprint(seed: Uint8Array): string {
  return Array.from(seed.slice(0, 8))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

// ------------------------------
// Misc
// ------------------------------

/**
 * Create a serial-runner closure that queues async work onto a single Promise
 * chain so concurrent callers can't interleave a mint/melt mid-flight. Each
 * runner instance owns its own chain — tests get isolation, production gets
 * one per receipt page. Errors thrown from `fn` are routed to `setStatus` so
 * the chain itself never rejects and subsequent run() calls keep flowing.
 */
export type SerialRunner = <T>(fn: () => Promise<T>) => Promise<T | undefined>;

export function createSerialRunner(
  setStatus: (msg: string, isError?: boolean) => void,
): SerialRunner {
  let chain: Promise<unknown> = Promise.resolve();
  return async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    const p = chain.then(fn).catch((e) => {
      setStatus(getErrorMessage(e), true);
      return undefined as unknown as T;
    });
    chain = p.then(() => undefined);
    return p;
  };
}

/**
 * Format a Unix-seconds target as `MM:SS` remaining. Returns `00:00` if the
 * target is in the past. nowMs is parameterised for deterministic testing.
 */
export function formatCountdown(
  targetUnixSeconds: number,
  nowMs: number = Date.now(),
): string {
  const remainingMs = targetUnixSeconds * 1000 - nowMs;
  const totalSeconds = Math.max(0, Math.floor(remainingMs / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  const mm = String(minutes).padStart(2, '0');
  const ss = String(seconds).padStart(2, '0');
  return `${mm}:${ss}`;
}

// ----------------------------------------------------------------------------
// Misc small helpers
// ----------------------------------------------------------------------------

/**
 * Pick the next backoff interval from a list, indexed by `streak` and capped
 * to the last entry. Used by pollOrderStatus to step through [5s, 15s, 30s]
 * as consecutive PENDING responses accumulate, dropping mint-side
 * amplification on long-routing LN payments. Negative streaks clamp to 0.
 */
export function selectPollIntervalMs(
  streak: number,
  intervals: readonly number[],
): number {
  if (intervals.length === 0) return 0;
  const step = Math.min(Math.max(0, streak), intervals.length - 1);
  return intervals[step];
}

/**
 * Mint-quote poll fallback cadence. The first minute stays at 12s (quick
 * settles catch fast); a long slid window (hours) backs off to 2 minutes
 * so the fallback stays polite to the mint when the WS is down.
 */
export const MINT_POLL_INTERVALS_MS: readonly number[] = [
  12_000, 12_000, 12_000, 12_000, 12_000, 30_000, 30_000, 60_000, 120_000,
];

/**
 * Classify the post-throw mint state probe inside meltTrustedProofsToVendor.
 * cashu-ts threw, we re-probed, and now need to decide whether the input
 * proofs were spent at the mint (PAID), are still safe (UNPAID), or
 * indeterminate (anything else — empty string from a 429, null from a
 * separate throw, or a state we don't recognise).
 */
export type MeltFailureBranch =
  'paid_inputs_spent' | 'unpaid_inputs_safe' | 'unknown_let_server_probe';

export function deriveMeltFailureBranch(
  postState: string | null | undefined,
): MeltFailureBranch {
  if (postState === 'PAID') return 'paid_inputs_spent';
  if (postState === 'UNPAID') return 'unpaid_inputs_safe';
  return 'unknown_let_server_probe';
}

/**
 * Build a REST endpoint URL from a base + a route, idempotent on slashes.
 * Both `cashu_wc.rest_root` and `cashu_wc.claim_route` / `.confirm_route` are
 * server-side locale-shaped strings — `rest_root` ends with '/' in WP's
 * default REST namespace, but a site that's filtered the namespace prefix
 * can change that, and the routes themselves arrive both with and without
 * a leading slash across WP versions. Concatenating naively yields
 * `//confirm-melt-quote` or `confirm-melt-quotefoo` depending on inputs.
 *
 * Contract: produces exactly one '/' between the two halves regardless of
 * trailing slash on `restRoot` or leading slash on `route`. Empty inputs
 * pass through unchanged so the caller can detect missing config.
 */
export function composeRestUrl(restRoot: string, route: string): string {
  if (!restRoot || !route) return '';
  return restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');
}

/**
 * Pull `payment_preimage` off a cashu-ts quote or melt-response wrapper
 * (some mint responses surface the preimage directly on the quote, some
 * wrap it under `.quote`). Returns the empty string when absent or
 * non-string.
 */
export function extractPaymentPreimage(source: unknown): string {
  if (!source || typeof source !== 'object') return '';
  const direct = (source as { payment_preimage?: unknown }).payment_preimage;
  if (typeof direct === 'string') return direct;
  const wrapped = (source as { quote?: { payment_preimage?: unknown } }).quote
    ?.payment_preimage;
  if (typeof wrapped === 'string') return wrapped;
  return '';
}

// ----------------------------------------------------------------------------
// Order status decision logic
// ----------------------------------------------------------------------------

/**
 * Server's response to /confirm_melt_quote. Mirrors ConfirmPaidResponse in
 * checkout.ts (which re-uses MeltQuoteState from cashu-ts). Decoupled here so
 * deriveOrderStatusActions can be unit-tested without importing cashu-ts.
 */
export type OrderStatusResponse = {
  ok?: boolean;
  state?: 'UNPAID' | 'PENDING' | 'PAID' | 'EXPIRED' | string;
  redirect?: string;
  message?: string;
  expiry?: number | null;
  last_attempt?: number | null;
};

export type OrderStatusAction =
  | { type: 'updateQuoteExpiry'; ms: number }
  | { type: 'clearStranded'; quoteId: string }
  | { type: 'markFinalised' }
  | { type: 'setStatus'; key: string; isError: boolean; args?: unknown[] }
  | { type: 'redirect'; url: string; withConfetti: boolean; delayMs: number };

export type OrderStatusContext = {
  json: OrderStatusResponse | null;
  nowSeconds: number;
  finalised: boolean;
  mintQuoteId: string;
  returnUrl: string;
};

/**
 * Pure decision logic for the `/confirm_melt_quote` polling response.
 *
 * Returns an ordered list of side-effect actions for the caller to dispatch.
 * Splitting the decision from the side effects lets us unit-test every
 * branch, especially the priority resolution below.
 *
 * Priority for non-terminal (non PAID / non EXPIRED) status updates:
 *
 *   1. UNPAID + last_attempt → "previous attempt didn't reach the mint"
 *   2. PENDING → "settling at mint"
 *   3. Expiry within 5 minutes → "expires in MM:SS" countdown
 *
 * These are mutually exclusive; only ONE setStatus is emitted. PAID and
 * EXPIRED short-circuit the whole list with their own redirect actions.
 */
export function deriveOrderStatusActions(ctx: OrderStatusContext): OrderStatusAction[] {
  const { json, nowSeconds, finalised, mintQuoteId, returnUrl } = ctx;
  const out: OrderStatusAction[] = [];
  if (!json) return out;

  // Server-authoritative expiry refresh. Trust each fresh response — the
  // data-attr value can drift if setup ran again. Convert unix seconds to ms.
  if (typeof json.expiry === 'number' && json.expiry > 0) {
    out.push({ type: 'updateQuoteExpiry', ms: json.expiry * 1000 });
  }

  // Terminal: PAID. Drop the stranded snapshot so a future reload of this
  // order doesn't try to re-melt already-spent proofs. Single-flight gate
  // via the finalised flag — both checkOrderStatus and claimMeltPaid can
  // race to PAID, but only one runs the confetti + redirect.
  if (json.state === 'PAID') {
    out.push({ type: 'clearStranded', quoteId: mintQuoteId });
    if (!finalised) {
      out.push({ type: 'markFinalised' });
      out.push({ type: 'setStatus', key: 'payment_confirmed', isError: false });
      out.push({
        type: 'redirect',
        url: json.redirect ?? returnUrl,
        withConfetti: true,
        delayMs: 2000,
      });
    }
    return out;
  }

  // Terminal: EXPIRED. Quote window closed; the stranded snapshot can't
  // help anymore and would only distract a future reload.
  if (json.state === 'EXPIRED') {
    out.push({ type: 'clearStranded', quoteId: mintQuoteId });
    out.push({ type: 'setStatus', key: 'invoice_expired', isError: true });
    out.push({
      type: 'redirect',
      url: returnUrl,
      withConfetti: false,
      delayMs: 2000,
    });
    return out;
  }

  // Non-terminal status, priority cascade.
  if (
    json.state === 'UNPAID' &&
    typeof json.last_attempt === 'number' &&
    json.last_attempt > 0
  ) {
    out.push({ type: 'setStatus', key: 'previous_attempt_failed', isError: true });
  } else if (json.state === 'PENDING') {
    out.push({ type: 'setStatus', key: 'settling_at_mint', isError: false });
  } else if (typeof json.expiry === 'number' && json.expiry > 0) {
    const secondsLeft = json.expiry - nowSeconds;
    if (secondsLeft < 300) {
      // Caller's t() will sprintf the formatted countdown into the
      // i18n string. We pass the target as args[0] so the dispatcher
      // can apply formatCountdown without us doing string formatting here.
      out.push({
        type: 'setStatus',
        key: 'invoice_expires_in',
        isError: secondsLeft < 60,
        args: [formatCountdown(json.expiry, nowSeconds * 1000)],
      });
    }
  }

  return out;
}

// ----------------------------------------------------------------------------
// Melt-flow outcome → action list (used by meltTrustedProofsToVendor)
// ----------------------------------------------------------------------------

/**
 * Discriminated outcome of the cashu-leg melt sequence. Each variant carries
 * the data the dispatcher needs to execute its action list — no global state
 * access from inside the projection function.
 *
 * Branches:
 *   entry_check_threw       — initial `checkMeltQuoteBolt11` rejected; we
 *                             don't know if the quote is still meltable, so
 *                             surface a recovery token + failure status.
 *   entry_paid_stale_snapshot — quote is already PAID at entry (typical
 *                             reload after a successful melt whose stranded-
 *                             proof snapshot wasn't cleared). NUT-09 restore
 *                             any orphan change-proofs, claim with the
 *                             quote's preimage.
 *   melt_succeeded          — `meltProofsBolt11` returned; inputs are spent
 *                             at the mint, change-proofs (if any) come from
 *                             the response. Save them, claim with the
 *                             response's preimage.
 *   melt_threw_inputs_spent — `meltProofsBolt11` threw but the post-throw
 *                             probe came back PAID: the mint spent the
 *                             inputs and dropped the response. Snapshot is
 *                             stale; NUT-09 restore change-proofs that
 *                             would've ridden the response, claim with empty
 *                             preimage (server falls back to one mint hit).
 *   melt_threw_inputs_safe  — post-throw probe is UNPAID: inputs were never
 *                             spent. Original input token is a valid
 *                             recovery; show it + failure status.
 *   melt_threw_state_unknown — post-throw probe is anything else (PENDING /
 *                             429 / probe also threw). Don't show recovery
 *                             (proofs might be spent) — surface a
 *                             "reconciling" status and notify the server, which
 *                             will probe and write the LN-leg pending marker
 *                             if the mint reports PENDING.
 */
export type MeltOutcome =
  | { kind: 'entry_check_threw'; encodedToken: string }
  | {
      kind: 'entry_paid_stale_snapshot';
      preimage: string;
      mintQuoteId: string;
    }
  | {
      kind: 'melt_succeeded';
      preimage: string;
      changeProofs: Proof[];
      mintQuoteId: string;
    }
  | { kind: 'melt_threw_inputs_spent'; mintQuoteId: string }
  | { kind: 'melt_threw_inputs_safe'; encodedToken: string }
  | { kind: 'melt_threw_state_unknown' };

/**
 * Action verbs the meltTrustedProofsToVendor dispatcher knows how to execute.
 * Designed so the projection function (actionsForMeltOutcome) is fully
 * deterministic and the dispatcher in checkout.ts is the only side-effect
 * surface — closure-coupled to the wallet handle, network helpers, and DOM.
 */
export type MeltAction =
  | { type: 'clearStranded'; mintQuoteId: string }
  | { type: 'restoreAndSaveChange' }
  | { type: 'saveResponseChange'; proofs: Proof[] }
  | { type: 'showRecovery'; token: string }
  | { type: 'setStatus'; key: string; isError: boolean }
  | { type: 'claim'; preimage: string };

/**
 * Project a melt outcome onto the deterministic action sequence the
 * dispatcher will execute.
 *
 * Mirrors the deriveOrderStatusActions pattern: pure data in, ordered
 * actions out, every branch pinned by a unit test.
 */
export function actionsForMeltOutcome(outcome: MeltOutcome): MeltAction[] {
  switch (outcome.kind) {
    case 'melt_succeeded':
      return [
        { type: 'clearStranded', mintQuoteId: outcome.mintQuoteId },
        { type: 'saveResponseChange', proofs: outcome.changeProofs },
        { type: 'setStatus', key: 'confirming_payment', isError: false },
        { type: 'claim', preimage: outcome.preimage },
      ];
    case 'entry_check_threw':
      return [
        { type: 'showRecovery', token: outcome.encodedToken },
        { type: 'setStatus', key: 'payment_failed', isError: true },
      ];
    case 'entry_paid_stale_snapshot':
      return [
        { type: 'clearStranded', mintQuoteId: outcome.mintQuoteId },
        { type: 'restoreAndSaveChange' },
        { type: 'setStatus', key: 'confirming_payment', isError: false },
        { type: 'claim', preimage: outcome.preimage },
      ];
    case 'melt_threw_inputs_spent':
      return [
        { type: 'clearStranded', mintQuoteId: outcome.mintQuoteId },
        { type: 'restoreAndSaveChange' },
        { type: 'setStatus', key: 'confirming_payment', isError: false },
        { type: 'claim', preimage: '' },
      ];
    case 'melt_threw_inputs_safe':
      return [
        { type: 'showRecovery', token: outcome.encodedToken },
        { type: 'setStatus', key: 'payment_failed', isError: true },
      ];
    case 'melt_threw_state_unknown':
      return [
        { type: 'setStatus', key: 'reconciling_with_mint', isError: true },
        { type: 'claim', preimage: '' },
      ];
  }
}

// ----------------------------------------------------------------------------
// Receipt-page root data — parsed once at init from data-attrs on #cashu-pay-root
// ----------------------------------------------------------------------------

export type QrMode = 'unified' | 'cashu' | 'lightning';

export type RootData = {
  orderId: number;
  orderKey: string;
  returnUrl: string;
  expectedAmount: number;
  quoteId: string;
  quoteExpiryMs: number;
  trustedMint: string;
  mintQuote: {
    id: string;
    request: string;
    amount: number;
    expiry: number | null;
  };
  payCallback: string;
  paymentId: string;
  description: string;
  defaultTab: QrMode;
};

/**
 * Minimal adapter shape for readRootData. Satisfied by jQuery's `$root` in
 * production (`{ data: (k) => $root.data(k) }`); satisfied by a plain object
 * lookup in tests so the validation rules + transforms run without
 * jsdom + jQuery.
 */
export type RootDataReader = { data: (key: string) => unknown };

/**
 * Project the receipt-page data-attrs to a fully validated RootData. Throws
 * `Bad order data` if any of the load-bearing fields is missing or
 * malformed. The defaultTab cascade falls back to 'unified' on an unknown
 * value so a stale render doesn't desync the QR tab.
 */
export function readRootData(reader: RootDataReader): RootData {
  const orderId = Number(reader.data('order-id'));
  const orderKey = String(reader.data('order-key') ?? '');
  const returnUrl = String(reader.data('return-url') ?? '');
  const expectedAmount = Number(reader.data('expected-amount') ?? 0);
  const quoteId = String(reader.data('melt-quote-id') ?? '');
  const quoteExpiryMs = Number(reader.data('spot-quote-expiry') ?? 0) * 1000;
  const trustedMint = String(reader.data('trusted-mint') ?? '');
  const mintQuoteId = String(reader.data('mint-quote-id') ?? '');
  const mintQuoteRequest = String(reader.data('mint-quote-request') ?? '');
  const mintQuoteAmount = Number(reader.data('mint-quote-amount') ?? 0);
  const mintQuoteExpiryRaw = Number(reader.data('mint-quote-expiry') ?? 0);
  const payCallback = String(reader.data('pay-callback') ?? '');
  const paymentId = String(reader.data('payment-id') ?? '');
  const description = String(reader.data('description') ?? '');

  const rawDefaultTab = String(reader.data('default-tab') ?? 'unified');
  const defaultTab: QrMode =
    rawDefaultTab === 'cashu' ||
    rawDefaultTab === 'lightning' ||
    rawDefaultTab === 'unified'
      ? rawDefaultTab
      : 'unified';

  if (
    !Number.isFinite(orderId) ||
    orderId <= 0 ||
    !orderKey ||
    !returnUrl ||
    !trustedMint ||
    !payCallback ||
    !paymentId ||
    !Number.isFinite(expectedAmount) ||
    expectedAmount <= 0 ||
    !quoteId ||
    !mintQuoteId ||
    !mintQuoteRequest ||
    !Number.isFinite(mintQuoteAmount) ||
    mintQuoteAmount <= 0
  ) {
    throw new Error('Bad order data');
  }

  return {
    orderId,
    orderKey,
    returnUrl,
    expectedAmount,
    quoteId,
    quoteExpiryMs,
    trustedMint,
    mintQuote: {
      id: mintQuoteId,
      request: mintQuoteRequest,
      amount: mintQuoteAmount,
      expiry: mintQuoteExpiryRaw > 0 ? mintQuoteExpiryRaw : null,
    },
    payCallback,
    paymentId,
    description,
    defaultTab,
  };
}
