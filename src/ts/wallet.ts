// Wallet-aware helpers extracted from checkout.ts for testability.
// Anything here needs `@cashu/cashu-ts`'s Wallet type but is otherwise
// side-effect-free (or scoped to a created-here Map for the cache).

import { ConsoleLogger, Proof, Wallet, sumProofs } from '@cashu/cashu-ts';
import { getErrorMessage } from './utils';

export type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

// Wallet cache: bounded so a long-lived tab doesn't reuse a Wallet with stale
// keyset state. Mint keyset rotations are rare but possible, and a stale
// wallet would silently produce proofs the mint rejects on next use.
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
 * Filtering to active sat keysets avoids hammering the mint walking
 * msat/btc keysets we'd never have minted into, and skips inactive
 * keysets (the mint won't have signatures against them for this seed).
 *
 * API note: cashu-ts v4.5.1 exposes keysets via wallet.keyChain.getKeysets()
 * (unit-filtered to the wallet's unit) and Keyset.isActive (not .active).
 */
export async function tryRestore(
  wallet: Wallet,
  targetAmount?: number,
): Promise<Proof[]> {
  const out: Proof[] = [];
  const keysets = wallet.keyChain
    .getKeysets()
    .filter((k) => k.unit === 'sat' && k.isActive);
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
