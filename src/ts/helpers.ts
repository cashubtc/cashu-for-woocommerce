// Pure, side-effect-free helpers extracted from checkout.ts for testability.
// Nothing here touches jQuery, cashu-ts wallets, or the global window.cashu_wc
// dictionary — exercising these functions only requires a localStorage-capable
// environment (jsdom in tests, the real browser at runtime).

import { sha512 } from '@noble/hashes/sha2.js';
import type { Proof } from '@cashu/cashu-ts';

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

export const CHANGE_PAYLOAD_TTL_MS = 60 * 60 * 1000;

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

// ------------------------------
// Stranded-proof recovery (Option B: localStorage persistence)
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
