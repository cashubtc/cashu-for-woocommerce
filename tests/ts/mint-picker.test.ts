import { beforeEach, describe, expect, test, vi } from 'vitest';
// Plain-JS admin asset: importing runs the IIFE, which exposes
// window.CashuMintPicker and (absent the l10n global) does nothing else.
import '../../assets/js/backend/cashu-mint-picker.js';

type Picker = {
  init: (
    doc: Document,
    l10n: typeof L10N,
    fetcher: (url: string) => Promise<unknown>,
  ) => { select: HTMLSelectElement; notice: HTMLElement } | null;
  auditorMints: (
    list: unknown[],
  ) => Array<{ name: string; url: string; description: string }>;
  mintLabel: (mint: { name?: string; url: string }) => string;
  combineDescription: (info: {
    description?: string;
    description_long?: string;
  }) => string;
};

const picker = (window as unknown as { CashuMintPicker: Picker }).CashuMintPicker;

const L10N = {
  auditorApi: 'https://auditor.example',
  starterMints: [
    { name: 'Minibits', url: 'https://mint.minibits.cash/Bitcoin' },
    { name: 'Coinos', url: 'https://mint.coinos.io' },
  ],
  i18n: {
    placeholder: '— Choose a popular mint —',
    discover: 'Discover more mints…',
    discovering: 'Discovering mints…',
    failed: 'Mint discovery failed — please try again later.',
  },
};

function okResponse(body: unknown) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

function setup(fetcher: (url: string) => Promise<unknown> = vi.fn()) {
  document.body.innerHTML = '<input id="cashu_trusted_mint" value="" />';
  const handle = picker.init(document, L10N, fetcher)!;
  const input = document.getElementById('cashu_trusted_mint') as HTMLInputElement;
  return { ...handle, input };
}

function labels(select: HTMLSelectElement): string[] {
  return Array.from(select.options).map((o) => o.textContent ?? '');
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('init', () => {
  test('injects select after the input: placeholder, starters, sentinel', () => {
    const { select, input } = setup();
    expect(input.nextElementSibling?.contains(select)).toBe(true);
    expect(labels(select)).toEqual([
      '— Choose a popular mint —',
      'Minibits — mint.minibits.cash/Bitcoin',
      'Coinos — mint.coinos.io',
      'Discover more mints…',
    ]);
  });

  test('returns null and injects nothing without the input', () => {
    document.body.innerHTML = '<p>no settings here</p>';
    expect(picker.init(document, L10N, vi.fn())).toBeNull();
    expect(document.querySelector('select')).toBeNull();
  });

  test('choosing a mint fills the input, fires change, resets the select', () => {
    const { select, input } = setup();
    const onInputChange = vi.fn();
    input.addEventListener('change', onInputChange);

    select.value = 'https://mint.coinos.io';
    select.dispatchEvent(new Event('change'));

    expect(input.value).toBe('https://mint.coinos.io');
    expect(onInputChange).toHaveBeenCalledTimes(1);
    expect(select.value).toBe(''); // back on the placeholder
  });
});

describe('discovery', () => {
  const AUDITOR = [
    {
      url: 'https://mint.flaky.example',
      state: 'OK',
      n_errors: 7,
      name: 'Flaky',
      info: JSON.stringify({
        description: 'We rug monthly.',
        description_long: 'We rug monthly. Donations go to charity.',
      }),
    },
    { url: 'https://mint.down.example', state: 'ERROR', n_errors: 0, name: 'Down' },
    {
      url: 'https://mint.solid.example/Bitcoin',
      state: 'OK',
      n_errors: 0,
      name: '',
      info: '{not json', // malformed auditor cache must not break discovery
    },
  ];

  function discover(select: HTMLSelectElement) {
    select.value = '__discover__';
    select.dispatchEvent(new Event('change'));
  }

  test('success: OK-only, fewest errors first, host fallback label, no sentinel', async () => {
    const fetcher = vi.fn(() => okResponse(AUDITOR));
    const { select } = setup(fetcher);

    discover(select);
    expect(select.disabled).toBe(true);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    expect(fetcher).toHaveBeenCalledWith('https://auditor.example/mints/');
    expect(labels(select)).toEqual([
      '— Choose a popular mint —',
      'mint.solid.example/Bitcoin', // no name → host+path label
      'Flaky — mint.flaky.example',
    ]);
  });

  test('failure: starters restored, sentinel back, notice shown', async () => {
    const fetcher = vi.fn(() => Promise.reject(new Error('offline')));
    const { select, notice } = setup(fetcher);

    discover(select);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    expect(labels(select)).toContain('Discover more mints…');
    expect(labels(select)).toContain('Minibits — mint.minibits.cash/Bitcoin');
    expect(notice.hidden).toBe(false);
    expect(notice.textContent).toBe(L10N.i18n.failed);
  });

  test('empty result is treated as failure', async () => {
    const fetcher = vi.fn(() =>
      okResponse([{ url: 'https://x.example', state: 'ERROR', n_errors: 0 }]),
    );
    const { select, notice } = setup(fetcher);

    discover(select);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    expect(notice.hidden).toBe(false);
    expect(labels(select)).toContain('Discover more mints…');
  });

  test('discovered options carry the description as tooltip and surface it on pick', async () => {
    const fetcher = vi.fn(() => okResponse(AUDITOR));
    const { select, notice, input } = setup(fetcher);

    discover(select);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    const flaky = Array.from(select.options).find(
      (o) => o.value === 'https://mint.flaky.example',
    )!;
    expect(flaky.title).toBe('We rug monthly. Donations go to charity.');

    select.value = 'https://mint.flaky.example';
    select.dispatchEvent(new Event('change'));

    expect(input.value).toBe('https://mint.flaky.example');
    expect(notice.hidden).toBe(false);
    expect(notice.textContent).toBe('We rug monthly. Donations go to charity.');
  });

  test('picking a mint without a description keeps the notice hidden', async () => {
    const fetcher = vi.fn(() => okResponse(AUDITOR));
    const { select, notice } = setup(fetcher);

    discover(select);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    select.value = 'https://mint.solid.example/Bitcoin'; // malformed info blob
    select.dispatchEvent(new Event('change'));

    expect(notice.hidden).toBe(true);
  });

  test('a later pick clears the failure notice', async () => {
    const fetcher = vi.fn(() => Promise.reject(new Error('offline')));
    const { select, notice, input } = setup(fetcher);

    discover(select);
    await vi.waitFor(() => expect(select.disabled).toBe(false));

    select.value = 'https://mint.coinos.io';
    select.dispatchEvent(new Event('change'));

    expect(notice.hidden).toBe(true);
    expect(input.value).toBe('https://mint.coinos.io');
  });
});

describe('pure helpers', () => {
  test('auditorMints filters non-OK and sorts by n_errors ascending', () => {
    const sorted = picker.auditorMints([
      { url: 'https://b.example', state: 'OK', n_errors: 3, name: 'B' },
      { url: 'https://a.example', state: 'OK', n_errors: 1, name: 'A' },
      { url: 'https://c.example', state: 'UNKNOWN', n_errors: 0, name: 'C' },
      { url: '', state: 'OK', n_errors: 0, name: 'no url' },
    ]);
    expect(sorted.map((m) => m.name)).toEqual(['A', 'B']);
  });

  test('mintLabel strips scheme and trailing slash, falls back to host', () => {
    expect(
      picker.mintLabel({ name: 'Minibits', url: 'https://mint.minibits.cash/Bitcoin' }),
    ).toBe('Minibits — mint.minibits.cash/Bitcoin');
    expect(picker.mintLabel({ name: '', url: 'https://mint.x.example/' })).toBe(
      'mint.x.example',
    );
  });

  // Mirrors MintLimits::mint_description() on the PHP side.
  test('combineDescription joins short and long, collapsing whitespace', () => {
    expect(
      picker.combineDescription({
        description: 'Cashu.cz mint.',
        description_long: 'It will  eventually\nrug pull you.',
      }),
    ).toBe('Cashu.cz mint. It will eventually rug pull you.');
  });

  test('combineDescription uses long alone when it repeats the short text', () => {
    expect(
      picker.combineDescription({
        description: 'We rug monthly.',
        description_long: 'We rug monthly. Donations go to charity.',
      }),
    ).toBe('We rug monthly. Donations go to charity.');
  });

  test('combineDescription caps at 400 chars with an ellipsis', () => {
    const result = picker.combineDescription({
      description: 'palabras y más palabras '.repeat(60),
    });
    expect(result.length).toBeLessThanOrEqual(400);
    expect(result.length).toBeGreaterThan(390);
    expect(result.endsWith('…')).toBe(true);
  });

  test('combineDescription tolerates missing fields', () => {
    expect(picker.combineDescription({})).toBe('');
    expect(picker.combineDescription({ description_long: ' long only ' })).toBe(
      'long only',
    );
  });
});
