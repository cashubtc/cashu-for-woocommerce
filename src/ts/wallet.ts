// Wallet-aware helpers extracted from checkout.ts for testability.
// Anything here needs `@cashu/cashu-ts`'s Wallet type but is otherwise
// side-effect-free (or scoped to a created-here Map for the cache).

import { ConsoleLogger, Proof, Wallet, sumProofs } from '@cashu/cashu-ts';
import { getErrorMessage } from './utils';

export type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

// Wallet cache: TTL-bounded as belt-and-braces against long-lived tabs.
// Since cashu-ts 4.9 the wallet's keyset snapshot self-repairs on mint
// keyset rotation, so a cached Wallet no longer risks minting into a
// rotated-out keyset; the TTL just bounds memory and any other drift.
// The cache key incorporates the seed fingerprint so two orders against
// the same mint never share a Wallet (different seeds = different
// deterministic counters; sharing a Wallet across seeds is a correctness bug).
type CachedWallet = { promise: Promise<Wallet>; createdAt: number };
export const WALLET_CACHE_TTL_MS = 10 * 60 * 1000; // 10 minutes

/**
 * Construct-or-fetch a cached cashu-ts Wallet. Tests can pass a custom
 * `walletFactory` to substitute a stub; the default factory creates a real
 * `new Wallet(...)` with the production logger. Each getter has its own
 * cache (Map) so tests stay isolated from one another and from production.
 */
export type WalletGetter = (
  mintUrl: string,
  unit: CurrencyUnit,
  seed: Uint8Array,
  fingerprint: string,
) => Promise<Wallet>;

export type WalletFactory = (
  mintUrl: string,
  unit: CurrencyUnit,
  seed: Uint8Array,
) => Promise<Wallet>;

const defaultWalletFactory: WalletFactory = async (mintUrl, unit, seed) => {
  const w = new Wallet(mintUrl, {
    unit,
    bip39seed: seed,
    logger: new ConsoleLogger('debug'),
  });
  await w.loadMint();
  return w;
};

export function createWalletGetter(
  factory: WalletFactory = defaultWalletFactory,
  ttlMs: number = WALLET_CACHE_TTL_MS,
  now: () => number = Date.now,
): WalletGetter {
  const cache = new Map<string, CachedWallet>();

  return function getWalletCached(mintUrl, unit, seed, fingerprint) {
    const key = `${String(mintUrl).replace(/\/+$/, '')}|${unit}|${fingerprint}`;
    const existing = cache.get(key);
    if (existing && now() - existing.createdAt < ttlMs) {
      return existing.promise;
    }
    if (existing) cache.delete(key);
    const promise = factory(mintUrl, unit, seed);
    promise.catch(() => cache.delete(key));
    cache.set(key, { promise, createdAt: now() });
    return promise;
  };
}

/**
 * Walk active sat-unit keysets and call NUT-09 wallet.restore(0, 64, ...)
 * on each, accumulating recovered Proofs. Used as the slow-path recovery
 * whenever a flow would otherwise have lost in-flight proofs (mint death,
 * melt death, change loss). cashu-ts splits an amount into power-of-two
 * denominations (popcount minting) so even large orders never exceed ~16
 * outputs per operation; 64 is a safe over-allocation. If 64 ever turns
 * out to be insufficient (e.g. cashu-ts changes its split strategy), swap
 * for wallet.batchRestore(300, 300, 0, keysetId) — same shape with
 * built-in gap-limit early-stop on consecutive empty batches.
 *
 * Walks ALL sat keysets, active first. The seed is per-order, so any sat
 * keyset active at any point during the order can hold signatures — and a
 * keyset that rotated out mid-order is inactive by the time we restore.
 * Active-first ordering plus the targetAmount early-break keeps the
 * common no-rotation case at one restore call. Non-sat keysets are still
 * skipped: we never mint into them, walking them just hammers the mint.
 */
export async function tryRestore(
  wallet: Wallet,
  targetAmount?: number,
): Promise<Proof[]> {
  const out: Proof[] = [];
  const keysets = wallet.keyChain
    .getKeysets()
    .filter((k) => k.unit === 'sat')
    .sort((a, b) => Number(b.isActive) - Number(a.isActive));
  for (const ks of keysets) {
    try {
      const { proofs, lastCounterWithSignature } = await wallet.restore(0, 64, {
        keysetId: ks.id,
      });
      // NUT-09 restore returns proofs but does NOT advance the wallet's
      // deterministic counter source. Without this, a subsequent mint or
      // melt operation against this wallet would derive blinded outputs
      // at counters the mint has already signed — collision territory.
      // wallet.restore returns the highest counter it saw a signature
      // for; advance to one past that so future ops use unused tuples.
      if (proofs.length > 0 && lastCounterWithSignature !== undefined) {
        await wallet.counters.advanceToAtLeast(ks.id, lastCounterWithSignature + 1);
      }
      out.push(...proofs);
      if (targetAmount && sumProofs(out).toNumber() >= targetAmount) break;
    } catch (e) {
      console.warn(`restore failed for keyset ${ks.id}:`, getErrorMessage(e));
    }
  }
  return out;
}
