import { describe, expect, beforeEach, test } from 'vitest';
import type { Proof } from '@cashu/cashu-ts';
import {
  CHANGE_PAYLOAD_KEY,
  CHANGE_PAYLOAD_MAX_ITEMS,
  CHANGE_PAYLOAD_TTL_MS,
  STRANDED_KEY_PREFIX,
  STRANDED_TTL_MS,
  clearStrandedProofs,
  deleteJson,
  deriveWalletSeed,
  formatCountdown,
  loadChangePayload,
  loadJson,
  loadStrandedProofs,
  rememberChangeItem,
  saveJson,
  saveStrandedProofs,
  seedFingerprint,
  strandedKey,
  sweepStaleStrandedProofs,
  type ChangeItem,
  type ChangePayload,
  type PersistedMintProofs,
} from '../../src/ts/helpers';

// Proof.amount is the branded `Amount` class from cashu-ts; for these tests
// we only round-trip proofs through localStorage and never run any cashu-ts
// math on them, so an unknown-cast is the pragmatic fixture path.
const sampleProof = (amount: number): Proof =>
  ({
    id: '00deadbeef',
    amount,
    secret: 'sec' + amount,
    C: 'C' + amount,
  }) as unknown as Proof;

beforeEach(() => {
  localStorage.clear();
});

// ----------------------------------------------------------------------------
// loadJson / saveJson / deleteJson
// ----------------------------------------------------------------------------

describe('loadJson / saveJson / deleteJson', () => {
  test('round-trip arbitrary JSON', () => {
    saveJson('key', { a: 1, b: [2, 3] });
    expect(loadJson<{ a: number; b: number[] }>('key')).toEqual({ a: 1, b: [2, 3] });
  });

  test('loadJson returns null for missing key', () => {
    expect(loadJson('missing')).toBeNull();
  });

  test('loadJson returns null for malformed JSON without throwing', () => {
    localStorage.setItem('bad', '{not json}');
    expect(loadJson('bad')).toBeNull();
  });

  test('deleteJson removes a previously stored key', () => {
    saveJson('key', 1);
    deleteJson('key');
    expect(loadJson('key')).toBeNull();
  });

  test('deleteJson on a missing key is a no-op', () => {
    expect(() => deleteJson('missing')).not.toThrow();
  });
});

// ----------------------------------------------------------------------------
// loadChangePayload
// ----------------------------------------------------------------------------

describe('loadChangePayload', () => {
  const KEY = 'cashu_wc_change';

  test('returns empty payload when storage is empty', () => {
    const p = loadChangePayload(KEY);
    expect(p.v).toBe(1);
    expect(p.items).toEqual([]);
  });

  test('returns stored payload when fresh', () => {
    const stored: ChangePayload = {
      v: 1,
      created: Date.now(),
      items: [{ mint: 'm', token: 't', amount: 10, kind: 'k', dust: false }],
    };
    saveJson(KEY, stored);
    expect(loadChangePayload(KEY).items).toHaveLength(1);
  });

  test('returns empty payload when stored is past TTL', () => {
    const stored: ChangePayload = {
      v: 1,
      created: Date.now() - CHANGE_PAYLOAD_TTL_MS - 1000,
      items: [{ mint: 'm', token: 't', amount: 10, kind: 'k', dust: false }],
    };
    saveJson(KEY, stored);
    expect(loadChangePayload(KEY).items).toEqual([]);
  });

  test('returns empty payload when stored items is not an array', () => {
    saveJson(KEY, { v: 1, created: Date.now(), items: 'oops' });
    expect(loadChangePayload(KEY).items).toEqual([]);
  });

  test('returns empty payload when stored is null', () => {
    localStorage.setItem(KEY, 'null');
    expect(loadChangePayload(KEY).items).toEqual([]);
  });
});

// ----------------------------------------------------------------------------
// rememberChangeItem
// ----------------------------------------------------------------------------

describe('rememberChangeItem', () => {
  const makeItem = (token: string): ChangeItem => ({
    mint: 'https://mint',
    token,
    amount: 7,
    kind: 'Change From Network Fee Reserve',
    dust: false,
  });

  test('persists a new item under the CHANGE_PAYLOAD_KEY', () => {
    rememberChangeItem(makeItem('t1'));
    const stored = loadJson<ChangePayload>(CHANGE_PAYLOAD_KEY);
    expect(stored?.items.map((i) => i.token)).toEqual(['t1']);
  });

  test('appends a new token without removing existing ones', () => {
    rememberChangeItem(makeItem('t1'));
    rememberChangeItem(makeItem('t2'));
    const stored = loadJson<ChangePayload>(CHANGE_PAYLOAD_KEY);
    expect(stored?.items.map((i) => i.token)).toEqual(['t1', 't2']);
  });

  test('does not duplicate a token already in the list', () => {
    rememberChangeItem(makeItem('t1'));
    rememberChangeItem(makeItem('t1'));
    rememberChangeItem(makeItem('t1'));
    const stored = loadJson<ChangePayload>(CHANGE_PAYLOAD_KEY);
    expect(stored?.items.map((i) => i.token)).toEqual(['t1']);
  });

  test('trims to the last CHANGE_PAYLOAD_MAX_ITEMS when more are added', () => {
    for (let i = 1; i <= CHANGE_PAYLOAD_MAX_ITEMS + 3; i++) {
      rememberChangeItem(makeItem(`t${i}`));
    }
    const stored = loadJson<ChangePayload>(CHANGE_PAYLOAD_KEY);
    expect(stored?.items.map((i) => i.token)).toEqual(['t4', 't5', 't6', 't7', 't8']);
  });

  test('survives a malformed prior payload by falling back to empty', () => {
    localStorage.setItem(CHANGE_PAYLOAD_KEY, '{not valid json}');
    rememberChangeItem(makeItem('t1'));
    const stored = loadJson<ChangePayload>(CHANGE_PAYLOAD_KEY);
    expect(stored?.items.map((i) => i.token)).toEqual(['t1']);
  });
});

// ----------------------------------------------------------------------------
// Stranded-proof persistence
// ----------------------------------------------------------------------------

describe('strandedKey / loadStrandedProofs / saveStrandedProofs', () => {
  test('strandedKey prefixes the quote id', () => {
    expect(strandedKey('Q')).toBe(STRANDED_KEY_PREFIX + 'Q');
  });

  test('round-trips proofs by quote id', () => {
    const proofs = [sampleProof(1), sampleProof(2)];
    saveStrandedProofs('Q', 'https://mint/', 3, proofs);
    expect(loadStrandedProofs('Q')).toEqual(proofs);
  });

  test('saveStrandedProofs is a no-op on empty proofs array', () => {
    saveStrandedProofs('Q', 'https://mint/', 0, []);
    expect(localStorage.getItem(strandedKey('Q'))).toBeNull();
  });

  test('loadStrandedProofs returns null when missing', () => {
    expect(loadStrandedProofs('absent')).toBeNull();
  });

  test('loadStrandedProofs returns null on quote id mismatch', () => {
    const stored: PersistedMintProofs = {
      v: 1,
      created: Date.now(),
      quote: 'OTHER',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(1)],
    };
    saveJson(strandedKey('Q'), stored);
    expect(loadStrandedProofs('Q')).toBeNull();
  });

  test('loadStrandedProofs returns null and deletes when past TTL', () => {
    const stored: PersistedMintProofs = {
      v: 1,
      created: Date.now() - STRANDED_TTL_MS - 1,
      quote: 'Q',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(1)],
    };
    saveJson(strandedKey('Q'), stored);
    expect(loadStrandedProofs('Q')).toBeNull();
    expect(localStorage.getItem(strandedKey('Q'))).toBeNull();
  });

  test('loadStrandedProofs returns null when proofs array is empty', () => {
    const stored: PersistedMintProofs = {
      v: 1,
      created: Date.now(),
      quote: 'Q',
      mint: 'm',
      expected: 1,
      proofs: [],
    };
    saveJson(strandedKey('Q'), stored);
    expect(loadStrandedProofs('Q')).toBeNull();
  });

  test('loadStrandedProofs returns null when version mismatches', () => {
    saveJson(strandedKey('Q'), {
      v: 2,
      created: Date.now(),
      quote: 'Q',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(1)],
    });
    expect(loadStrandedProofs('Q')).toBeNull();
  });

  test('clearStrandedProofs removes the entry', () => {
    saveStrandedProofs('Q', 'm', 1, [sampleProof(1)]);
    clearStrandedProofs('Q');
    expect(loadStrandedProofs('Q')).toBeNull();
  });
});

// ----------------------------------------------------------------------------
// sweepStaleStrandedProofs
// ----------------------------------------------------------------------------

describe('sweepStaleStrandedProofs', () => {
  test('drops past-TTL entries and keeps fresh ones', () => {
    const fresh: PersistedMintProofs = {
      v: 1,
      created: Date.now(),
      quote: 'fresh',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(1)],
    };
    const stale: PersistedMintProofs = {
      v: 1,
      created: Date.now() - STRANDED_TTL_MS - 1000,
      quote: 'stale',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(2)],
    };
    saveJson(strandedKey('fresh'), fresh);
    saveJson(strandedKey('stale'), stale);

    sweepStaleStrandedProofs();

    expect(loadStrandedProofs('fresh')).not.toBeNull();
    expect(localStorage.getItem(strandedKey('stale'))).toBeNull();
  });

  test('drops version-mismatched entries', () => {
    saveJson(strandedKey('v2'), {
      v: 2,
      created: Date.now(),
      quote: 'v2',
      mint: 'm',
      expected: 1,
      proofs: [sampleProof(1)],
    });
    sweepStaleStrandedProofs();
    expect(localStorage.getItem(strandedKey('v2'))).toBeNull();
  });

  test('ignores keys without the stranded prefix', () => {
    saveJson('cashu_wc_change', { v: 1, created: Date.now(), items: [] });
    sweepStaleStrandedProofs();
    expect(localStorage.getItem('cashu_wc_change')).not.toBeNull();
  });

  test('no-op on empty localStorage', () => {
    expect(() => sweepStaleStrandedProofs()).not.toThrow();
  });
});

// ----------------------------------------------------------------------------
// deriveWalletSeed / seedFingerprint
// ----------------------------------------------------------------------------

describe('deriveWalletSeed', () => {
  test('produces a 64-byte Uint8Array', () => {
    const seed = deriveWalletSeed('orderKey', 'mintQuoteId');
    expect(seed).toBeInstanceOf(Uint8Array);
    expect(seed.length).toBe(64);
  });

  test('is deterministic for the same inputs', () => {
    const a = deriveWalletSeed('order', 'quote');
    const b = deriveWalletSeed('order', 'quote');
    expect(a).toEqual(b);
  });

  test('differs when orderKey changes', () => {
    const a = deriveWalletSeed('order1', 'quote');
    const b = deriveWalletSeed('order2', 'quote');
    expect(a).not.toEqual(b);
  });

  test('differs when mintQuoteId changes', () => {
    const a = deriveWalletSeed('order', 'quote1');
    const b = deriveWalletSeed('order', 'quote2');
    expect(a).not.toEqual(b);
  });
});

describe('seedFingerprint', () => {
  test('produces a 16-hex-character string', () => {
    const seed = deriveWalletSeed('order', 'quote');
    const fp = seedFingerprint(seed);
    expect(fp).toMatch(/^[0-9a-f]{16}$/);
  });

  test('is deterministic for the same seed', () => {
    const seed = deriveWalletSeed('order', 'quote');
    expect(seedFingerprint(seed)).toBe(seedFingerprint(seed));
  });

  test('differs when seed differs', () => {
    const a = seedFingerprint(deriveWalletSeed('order1', 'quote'));
    const b = seedFingerprint(deriveWalletSeed('order2', 'quote'));
    expect(a).not.toBe(b);
  });

  test('only depends on the first 8 bytes of the seed', () => {
    const seed = new Uint8Array(64);
    seed[0] = 0xde;
    seed[7] = 0xad;
    seed[63] = 0xff; // beyond the first 8 — should not affect fingerprint
    const fp1 = seedFingerprint(seed);
    seed[63] = 0x00;
    const fp2 = seedFingerprint(seed);
    expect(fp1).toBe(fp2);
  });
});

// ----------------------------------------------------------------------------
// formatCountdown
// ----------------------------------------------------------------------------

describe('formatCountdown', () => {
  test('renders MM:SS for a future target', () => {
    const now = 1_000_000_000_000;
    // 90 seconds in the future
    expect(formatCountdown(now / 1000 + 90, now)).toBe('01:30');
  });

  test('renders 00:00 for a target in the past', () => {
    const now = 1_000_000_000_000;
    expect(formatCountdown(now / 1000 - 60, now)).toBe('00:00');
  });

  test('renders 00:00 at exact boundary', () => {
    const now = 1_000_000_000_000;
    expect(formatCountdown(now / 1000, now)).toBe('00:00');
  });

  test('pads single-digit minutes and seconds with leading zero', () => {
    const now = 1_000_000_000_000;
    expect(formatCountdown(now / 1000 + 65, now)).toBe('01:05');
  });

  test('handles long durations (60+ minutes)', () => {
    const now = 1_000_000_000_000;
    expect(formatCountdown(now / 1000 + 60 * 75, now)).toBe('75:00');
  });
});
