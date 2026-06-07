import { describe, expect, test, vi } from 'vitest';
import type { Proof, Wallet } from '@cashu/cashu-ts';
import { createWalletGetter, tryRestore } from '../../src/ts/wallet';

// ----------------------------------------------------------------------------
// Stub helpers
// ----------------------------------------------------------------------------

type RestoreReturn = {
  proofs: Proof[];
  lastCounterWithSignature?: number;
};

type FakeKeyset = { id: string; unit: string; isActive: boolean };

/**
 * Build a minimal Wallet-shaped stub for tryRestore tests. Only the
 * methods tryRestore actually uses are stubbed; everything else is
 * left undefined.
 */
function makeStubWallet(opts: {
  keysets: FakeKeyset[];
  restore?: (
    start: number,
    count: number,
    cfg: { keysetId: string },
  ) => Promise<RestoreReturn>;
  advanceToAtLeast?: (keysetId: string, n: number) => Promise<void>;
}): Wallet {
  const restore = opts.restore ?? (async () => ({ proofs: [] }));
  const advanceToAtLeast = opts.advanceToAtLeast ?? vi.fn().mockResolvedValue(undefined);
  return {
    keyChain: { getKeysets: () => opts.keysets },
    restore,
    counters: { advanceToAtLeast },
  } as unknown as Wallet;
}

const sampleProof = (amount: number): Proof =>
  ({
    id: '00deadbeef',
    amount,
    secret: 'sec' + amount,
    C: 'C' + amount,
  }) as unknown as Proof;

// ----------------------------------------------------------------------------
// tryRestore
// ----------------------------------------------------------------------------

describe('tryRestore', () => {
  test('returns empty array when wallet has no keysets', async () => {
    const wallet = makeStubWallet({ keysets: [] });
    expect(await tryRestore(wallet)).toEqual([]);
  });

  test('returns empty when no keysets are active sat', async () => {
    const wallet = makeStubWallet({
      keysets: [
        { id: 'inactive', unit: 'sat', isActive: false },
        { id: 'msat', unit: 'msat', isActive: true },
      ],
    });
    expect(await tryRestore(wallet)).toEqual([]);
  });

  test('walks each active sat keyset exactly once with start=0 count=64', async () => {
    const calls: Array<{ start: number; count: number; keysetId: string }> = [];
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async (start, count, cfg) => {
        calls.push({ start, count, keysetId: cfg.keysetId });
        return { proofs: [] };
      },
    });
    await tryRestore(wallet);
    expect(calls).toEqual([
      { start: 0, count: 64, keysetId: 'A' },
      { start: 0, count: 64, keysetId: 'B' },
    ]);
  });

  test('accumulates proofs across keysets', async () => {
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        if (cfg.keysetId === 'A')
          return { proofs: [sampleProof(1)], lastCounterWithSignature: 0 };
        return { proofs: [sampleProof(2), sampleProof(4)], lastCounterWithSignature: 1 };
      },
    });
    const out = await tryRestore(wallet);
    expect(out).toHaveLength(3);
    expect(out.map((p) => p.amount as unknown as number)).toEqual([1, 2, 4]);
  });

  test('advances counter to lastCounterWithSignature + 1 when proofs are returned', async () => {
    const advance = vi.fn().mockResolvedValue(undefined);
    const wallet = makeStubWallet({
      keysets: [{ id: 'A', unit: 'sat', isActive: true }],
      restore: async () => ({ proofs: [sampleProof(1)], lastCounterWithSignature: 9 }),
      advanceToAtLeast: advance,
    });
    await tryRestore(wallet);
    expect(advance).toHaveBeenCalledWith('A', 10);
  });

  test('does NOT advance counter when no proofs returned', async () => {
    const advance = vi.fn().mockResolvedValue(undefined);
    const wallet = makeStubWallet({
      keysets: [{ id: 'A', unit: 'sat', isActive: true }],
      restore: async () => ({ proofs: [], lastCounterWithSignature: 9 }),
      advanceToAtLeast: advance,
    });
    await tryRestore(wallet);
    expect(advance).not.toHaveBeenCalled();
  });

  test('does NOT advance counter when lastCounterWithSignature is undefined', async () => {
    const advance = vi.fn().mockResolvedValue(undefined);
    const wallet = makeStubWallet({
      keysets: [{ id: 'A', unit: 'sat', isActive: true }],
      restore: async () => ({ proofs: [sampleProof(1)] }),
      advanceToAtLeast: advance,
    });
    await tryRestore(wallet);
    expect(advance).not.toHaveBeenCalled();
  });

  test('early-exits when targetAmount is reached', async () => {
    let bCalled = false;
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        if (cfg.keysetId === 'A')
          return { proofs: [sampleProof(10)], lastCounterWithSignature: 0 };
        bCalled = true;
        return { proofs: [sampleProof(5)], lastCounterWithSignature: 0 };
      },
    });
    const out = await tryRestore(wallet, 10);
    expect(out).toHaveLength(1);
    expect(bCalled).toBe(false);
  });

  test('does not early-exit when targetAmount not yet reached', async () => {
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        if (cfg.keysetId === 'A')
          return { proofs: [sampleProof(3)], lastCounterWithSignature: 0 };
        return { proofs: [sampleProof(7)], lastCounterWithSignature: 0 };
      },
    });
    const out = await tryRestore(wallet, 10);
    expect(out).toHaveLength(2);
  });

  test('continues to next keyset when one keyset.restore throws', async () => {
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        if (cfg.keysetId === 'A') throw new Error('mint timeout');
        return { proofs: [sampleProof(5)], lastCounterWithSignature: 0 };
      },
    });
    const out = await tryRestore(wallet);
    expect(out).toHaveLength(1);
  });

  test('returns empty array when all keyset restores throw', async () => {
    const wallet = makeStubWallet({
      keysets: [
        { id: 'A', unit: 'sat', isActive: true },
        { id: 'B', unit: 'sat', isActive: true },
      ],
      restore: async () => {
        throw new Error('boom');
      },
    });
    expect(await tryRestore(wallet)).toEqual([]);
  });

  test('skips msat keysets even when active', async () => {
    const calls: string[] = [];
    const wallet = makeStubWallet({
      keysets: [
        { id: 'sat', unit: 'sat', isActive: true },
        { id: 'msat', unit: 'msat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        calls.push(cfg.keysetId);
        return { proofs: [] };
      },
    });
    await tryRestore(wallet);
    expect(calls).toEqual(['sat']);
  });

  test('skips inactive sat keysets', async () => {
    const calls: string[] = [];
    const wallet = makeStubWallet({
      keysets: [
        { id: 'old', unit: 'sat', isActive: false },
        { id: 'new', unit: 'sat', isActive: true },
      ],
      restore: async (_s, _c, cfg) => {
        calls.push(cfg.keysetId);
        return { proofs: [] };
      },
    });
    await tryRestore(wallet);
    expect(calls).toEqual(['new']);
  });
});

// ----------------------------------------------------------------------------
// createWalletGetter
// ----------------------------------------------------------------------------

describe('createWalletGetter', () => {
  const seed = new Uint8Array(64).fill(7);

  function makeFactoryStub() {
    const calls: Array<{ mintUrl: string; unit: string; seed: Uint8Array }> = [];
    const factory = async (mintUrl: string, unit: string, seed: Uint8Array) => {
      calls.push({ mintUrl, unit, seed });
      return { mintUrl, unit } as unknown as Wallet;
    };
    return { calls, factory };
  }

  test('returns the same promise on cache hit (no second factory call)', async () => {
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory);
    const a = get('https://mint.example/', 'sat', seed, 'fp');
    const b = get('https://mint.example/', 'sat', seed, 'fp');
    await Promise.all([a, b]);
    expect(calls).toHaveLength(1);
  });

  test('creates a new wallet on cache miss', async () => {
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory);
    await get('https://mint.example/', 'sat', seed, 'fp1');
    await get('https://mint.example/', 'sat', seed, 'fp2');
    expect(calls).toHaveLength(2);
  });

  test('treats trailing slash differences as the same mint URL', async () => {
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory);
    await get('https://mint.example/', 'sat', seed, 'fp');
    await get('https://mint.example///', 'sat', seed, 'fp');
    expect(calls).toHaveLength(1);
  });

  test('different fingerprints route to different wallets', async () => {
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory);
    await get('https://mint.example/', 'sat', seed, 'fpA');
    await get('https://mint.example/', 'sat', seed, 'fpB');
    expect(calls).toHaveLength(2);
  });

  test('different units route to different wallets', async () => {
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory);
    await get('https://mint.example/', 'sat', seed, 'fp');
    await get('https://mint.example/', 'msat', seed, 'fp');
    expect(calls).toHaveLength(2);
  });

  test('cache TTL expiry triggers a fresh factory call', async () => {
    let nowMs = 1_000_000;
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory, 1000, () => nowMs);
    await get('m', 'sat', seed, 'fp');
    nowMs += 2000; // past TTL
    await get('m', 'sat', seed, 'fp');
    expect(calls).toHaveLength(2);
  });

  test('fresh-within-TTL hits the cache', async () => {
    let nowMs = 1_000_000;
    const { calls, factory } = makeFactoryStub();
    const get = createWalletGetter(factory, 1000, () => nowMs);
    await get('m', 'sat', seed, 'fp');
    nowMs += 500; // still within TTL
    await get('m', 'sat', seed, 'fp');
    expect(calls).toHaveLength(1);
  });

  test('factory rejection evicts the cache so the next call retries', async () => {
    let attempt = 0;
    const factory = async () => {
      attempt += 1;
      if (attempt === 1) throw new Error('first call fails');
      return { ok: true } as unknown as Wallet;
    };
    const get = createWalletGetter(factory);
    await expect(get('m', 'sat', seed, 'fp')).rejects.toThrow('first call fails');
    // Without eviction the cached rejected promise would be returned again.
    const second = await get('m', 'sat', seed, 'fp');
    expect(second).toEqual({ ok: true });
    expect(attempt).toBe(2);
  });

  test('each getter has its own cache (test isolation)', async () => {
    const a = makeFactoryStub();
    const b = makeFactoryStub();
    const getA = createWalletGetter(a.factory);
    const getB = createWalletGetter(b.factory);
    await getA('m', 'sat', seed, 'fp');
    await getB('m', 'sat', seed, 'fp');
    expect(a.calls).toHaveLength(1);
    expect(b.calls).toHaveLength(1);
  });
});
