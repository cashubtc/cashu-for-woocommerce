import {
  getEncodedToken,
  Wallet,
  sumProofs,
  Proof,
  MeltQuoteState,
  MeltQuoteBolt11Response,
  MeltProofsResponse,
  ConsoleLogger,
  PaymentRequest,
  PaymentRequestTransportType,
} from '@cashu/cashu-ts';
import { copyTextToClipboard, doConfettiBomb, delay, getErrorMessage } from './utils';

// ------------------------------
// Types
// ------------------------------

type CashuWindow = Window & {
  cashu_wc?: {
    rest_root?: string;
    confirm_route?: string;
    claim_route?: string;
    symbol: string;
    i18n?: Record<string, string>;
  };
};
declare const window: CashuWindow;

declare const wp: { i18n: { sprintf: (format: string, ...args: any[]) => string } };

declare const QRCode: any;

type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

type ConfirmPaidResponse = {
  ok?: boolean;
  state?: MeltQuoteState | 'EXPIRED';
  redirect?: string;
  message?: string;
  expiry?: number | null;
};

type QrMode = 'unified' | 'cashu' | 'lightning';

type RootData = {
  orderId: number;
  orderKey: string;
  returnUrl: string;
  expectedAmount: number; // sats the wallet must cover (invoice + mint fee_reserve + input buffer)
  quoteId: string; // server-side melt quote id (vendor payment)
  quoteExpiryMs: number; // spot quote expiry, milliseconds, may be 0
  trustedMint: string;
  // Mint quote (customer payment side), authoritative server copy — never
  // regenerated client-side. The customer's LN payment goes against this
  // BOLT11; the browser claims proofs against this quote_id.
  mintQuote: {
    id: string;
    request: string;
    amount: number;
    expiry: number | null;
  };
  payCallback: string; // NUT-18 HTTP transport target
  paymentId: string; // deterministic id matching what the PR encodes
  description: string; // human-readable PR memo
};

type StoredMintQuote = {
  mint: string;
  amount: number;
  quote: string;
  request: string;
  expiry?: number | null;
};

type ChangeItem = {
  mint: string;
  token: string;
  amount: number;
  kind: string;
  dust: boolean;
};

type ChangePayload = {
  v: 1;
  created: number;
  items: ChangeItem[];
};

// ------------------------------
// Helpers
// ------------------------------

const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

// Wallet cache: bounded so a long-lived tab doesn't reuse a Wallet with stale
// keyset state. Mint keyset rotations are rare but possible, and a stale
// wallet would silently produce proofs the mint rejects on next use.
type CachedWallet = { promise: Promise<Wallet>; createdAt: number };
const WALLET_CACHE_TTL_MS = 10 * 60 * 1000; // 10 minutes
const walletCache = new Map<string, CachedWallet>();

function getWalletCached(mintUrl: string, unit: CurrencyUnit = 'sat'): Promise<Wallet> {
  const key = `${String(mintUrl).replace(/\/+$/, '')}|${unit}`;
  const existing = walletCache.get(key);
  if (existing && Date.now() - existing.createdAt < WALLET_CACHE_TTL_MS) {
    return existing.promise;
  }
  if (existing) walletCache.delete(key);
  const promise = (async () => {
    const w = new Wallet(mintUrl, { unit, logger: new ConsoleLogger('debug') });
    await w.loadMint();
    return w;
  })();
  promise.catch(() => walletCache.delete(key));
  walletCache.set(key, { promise, createdAt: Date.now() });
  return promise;
}

function readRootData($root: JQuery<HTMLElement>): RootData {
  const orderId = Number($root.data('order-id'));
  const orderKey = String($root.data('order-key') ?? '');
  const returnUrl = String($root.data('return-url') ?? '');
  const expectedAmount = Number($root.data('expected-amount') ?? 0);
  const quoteId = String($root.data('melt-quote-id') ?? '');
  const quoteExpiryMs = Number($root.data('spot-quote-expiry') ?? 0) * 1000;
  const trustedMint = String($root.data('trusted-mint') ?? '');
  const mintQuoteId = String($root.data('mint-quote-id') ?? '');
  const mintQuoteRequest = String($root.data('mint-quote-request') ?? '');
  const mintQuoteAmount = Number($root.data('mint-quote-amount') ?? 0);
  const mintQuoteExpiryRaw = Number($root.data('mint-quote-expiry') ?? 0);
  const payCallback = String($root.data('pay-callback') ?? '');
  const paymentId = String($root.data('payment-id') ?? '');
  const description = String($root.data('description') ?? '');

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
  };
}

function sameMint(a: string, b: string): boolean {
  try {
    const ua = new URL(a);
    const ub = new URL(b);
    const normA = ua.origin + ua.pathname.replace(/\/+$/, '');
    const normB = ub.origin + ub.pathname.replace(/\/+$/, '');
    return normA === normB;
  } catch {
    return a.replace(/\/+$/, '') === b.replace(/\/+$/, '');
  }
}

function t(key: string, ...args: any[]): string {
  const dict = window.cashu_wc?.i18n ?? {};
  const raw = dict[key] ?? key;
  if (!args.length) return raw;
  try {
    return wp.i18n.sprintf(raw, ...args);
  } catch {
    return raw;
  }
}

// ------------------------------
// LocalStorage helpers
// ------------------------------

function loadJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

function saveJson(key: string, val: any): void {
  try {
    localStorage.setItem(key, JSON.stringify(val));
  } catch {
    // ignore
  }
}

function deleteJson(key: string): void {
  try {
    localStorage.removeItem(key);
  } catch {
    // ignore
  }
}

function loadChangePayload(key: string): ChangePayload {
  try {
    const parsed = loadJson<ChangePayload>(key);
    if (
      !parsed ||
      !Array.isArray(parsed.items) ||
      Date.now() - parsed.created > 60 * 60 * 1000
    ) {
      return { v: 1, created: Date.now(), items: [] };
    }
    return parsed;
  } catch {
    return { v: 1, created: Date.now(), items: [] };
  }
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

const STRANDED_KEY_PREFIX = 'cashu_wc_minted_';
// Slightly longer than the typical mint quote / merchant melt quote lifetime
// so a customer who refreshes well into a stalled flow can still recover.
const STRANDED_TTL_MS = 24 * 60 * 60 * 1000;

type PersistedMintProofs = {
  v: 1;
  created: number;
  quote: string;
  mint: string;
  expected: number;
  proofs: Proof[];
};

function strandedKey(quoteId: string): string {
  return STRANDED_KEY_PREFIX + quoteId;
}

function loadStrandedProofs(quoteId: string): Proof[] | null {
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

function saveStrandedProofs(
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

function clearStrandedProofs(quoteId: string): void {
  deleteJson(strandedKey(quoteId));
}

// Bounded sweep of localStorage for stranded-proof entries past TTL. Called
// once at init so abandoned-order keys don't accumulate over the long tail.
function sweepStaleStrandedProofs(): void {
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
    for (const key of stale) {
      try {
        localStorage.removeItem(key);
      } catch {
        // ignore
      }
    }
  } catch {
    // ignore
  }
}

// ------------------------------
// Bootstrap checkout
// ------------------------------

/**
 * Two settlement paths converge on the same order:
 *
 * Lightning leg: customer pays the mint-quote BOLT11 with any LN wallet. Browser
 * (cashu-ts) mints proofs and melts them client-side against the merchant melt
 * quote — keeps mint-facing load distributed.
 *
 * Cashu leg: customer's cashu wallet scans the NUT-18 payment request and POSTs
 * proofs to the plugin's pay callback. The server then melts those proofs against
 * the same merchant melt quote.
 *
 * Either path causes the mint to mark the merchant melt quote PAID; the browser's
 * polling detects that and redirects to the order-received page.
 */

jQuery(function ($) {
  const $root = $('#cashu-pay-root');
  if (!$root.length) return;
  const $scope = $root.next('section.cashu-checkout');
  if (!$scope.length) return;
  const $status = $scope.find('.cashu-status');
  const $qr = $scope.find('[data-cashu-qr]');
  const $tabs = $scope.find('[data-cashu-tab]');
  const $recovery = $scope.find('[data-cashu-recovery]');
  const $recoveryCopy = $scope.find('[data-cashu-recovery-copy]');
  let recoveryToken = '';
  const setStatus = (msg: string, isError: boolean = false) => {
    const color = isError ? 'var(--cashu-warning)' : 'var(--cashu-status)';
    $status.text(msg).css('background-color', color);
  };

  let data: RootData;
  try {
    data = readRootData($root);
  } catch (_e) {
    $status.text(t('data_incomplete'));
    return;
  }

  // Pre-computed QR payloads (filled in by renderQr once the mint quote is ready)
  let qrTexts: Record<QrMode, string> = { unified: '', cashu: '', lightning: '' };
  let currentMode: QrMode = 'unified';

  let chain: Promise<any> = Promise.resolve();
  let mintHandleP: Promise<void> | null = null;
  let userPending = 0;
  const trustedWalletP = getWalletCached(data.trustedMint, 'sat');
  const ls = {
    change: 'cashu_wc_change',
  };

  // The mint quote is now server-authoritative (rendered into data-attrs by
  // the receipt page). Use it directly; no localStorage caching, no race
  // against a page refresh.
  const mintQuote: StoredMintQuote = {
    mint: data.trustedMint,
    amount: data.mintQuote.amount,
    quote: data.mintQuote.id,
    request: data.mintQuote.request,
    expiry: data.mintQuote.expiry,
  };

  // Best-effort clean up legacy localStorage from earlier versions of the
  // plugin; harmless if nothing is there.
  try {
    deleteJson('cashu_wc_mq');
    deleteJson('cashu_wc_recovery');
    deleteJson(ls.change);
  } catch {}

  // Drop abandoned-order stranded-proof snapshots past TTL so localStorage
  // doesn't accumulate over the long tail. Active recovery for THIS quote
  // happens in startMintQuoteWatcher; nothing here removes that key.
  sweepStaleStrandedProofs();

  void startAsyncProcesses().catch(() => {
    setStatus(t('invoice_failed'), true);
  });

  // ------------------------------
  // Tab switching
  // ------------------------------

  $tabs.off('click').on('click', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const mode = String($btn.data('cashu-tab') ?? 'unified') as QrMode;
    if (mode === currentMode) return;
    currentMode = mode;
    $tabs.removeClass('is-active').attr('aria-selected', 'false');
    $btn.addClass('is-active').attr('aria-selected', 'true');
    if (qrTexts[mode]) drawQr(qrTexts[mode]);
  });

  $recoveryCopy.off('click').on('click', async () => {
    if (!recoveryToken) return;
    copyTextToClipboard(recoveryToken);
    const original = $recoveryCopy.text();
    $recoveryCopy.text(t('copied'));
    await delay(1000);
    $recoveryCopy.text(original);
  });

  function showRecovery(token: string): void {
    recoveryToken = token;
    $recovery.removeAttr('hidden');
  }

  // ------------------------------
  // Checkout Helpers
  // ------------------------------

  async function startAsyncProcesses(): Promise<void> {
    void renderQr();
    void startMintQuoteWatcher();
    void run(() => checkOrderStatus());
  }

  function drawQr(text: string): void {
    const el = $qr.get(0) as HTMLElement | undefined;
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, {
      text,
      width: 300,
      height: 300,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.Q,
    });
  }

  async function renderQr(): Promise<void> {
    const mq = mintQuote;

    const lightningUri = 'lightning:' + mq.request;

    // cashu-ts v4 PaymentRequest constructor is positional. Args, in order:
    // transport[], id, amount, unit, mints[], description, singleUse, nut10.
    // We pass singleUse=true and nut10=undefined (no NUT-10 lock — the server
    // enforces ownership via order_key + payment_id).
    const pr = new PaymentRequest(
      [{ type: PaymentRequestTransportType.POST, target: data.payCallback }],
      data.paymentId,
      data.expectedAmount,
      'sat',
      [data.trustedMint],
      data.description,
      true,
      undefined,
    );
    const creq = pr.toEncodedCreqB();

    const unifiedUri =
      'bitcoin:?lightning=' +
      encodeURIComponent(mq.request) +
      '&creq=' +
      encodeURIComponent(creq);

    qrTexts = {
      unified: unifiedUri,
      cashu: creq,
      lightning: lightningUri,
    };

    drawQr(qrTexts[currentMode]);

    // Copy current QR text on click
    const $qrWrap = $qr.parent();
    $qrWrap.off('click').on('click', async () => {
      const txt = qrTexts[currentMode];
      if (!txt) return;
      copyTextToClipboard(txt);
      setStatus(t('copied'));
      await delay(500);
      setStatus(t('waiting_for_payment'));
    });
  }

  async function run<T>(
    fn: () => Promise<T>,
    opts: { user?: boolean } = {},
  ): Promise<T | undefined> {
    const isUser = !!opts.user;

    if (isUser && userPending > 0) {
      setStatus(t('payment_in_progress'), true);
      return Promise.resolve(undefined);
    }

    if (isUser) {
      userPending++;
    }

    const p = chain.then(fn).catch((e) => {
      const msg = getErrorMessage(e);
      setStatus(msg, true);
      return undefined as unknown as T;
    });

    chain = p.then(() => undefined);

    try {
      return await p;
    } finally {
      if (isUser) {
        userPending--;
      }
    }
  }

  async function saveProofs(changeProofs: Proof[], wallet: Wallet): Promise<void> {
    if (changeProofs.length < 1) {
      return;
    }
    const changeAmt = sumProofs(changeProofs).toNumber();
    const changeFees = wallet.getFeesForProofs(changeProofs).toNumber();
    const tokenStr = getEncodedToken({
      mint: wallet.mint.mintUrl,
      proofs: changeProofs,
      unit: 'sat',
    });
    const kind = sameMint(wallet.mint.mintUrl, data.trustedMint)
      ? t('change_from_network')
      : t('change_from_token');
    rememberChangeItem({
      mint: wallet.mint.mintUrl,
      token: tokenStr,
      amount: changeAmt,
      kind,
      dust: changeAmt <= changeFees,
    });
  }

  function rememberChangeItem(item: ChangeItem): void {
    const payload = loadChangePayload(ls.change);
    const exists = payload.items.some((x) => x.token === item.token);
    if (!exists) payload.items.push(item);
    payload.items = payload.items.slice(-5);
    saveJson(ls.change, payload);
  }

  // ------------------------------
  // Mint Quote - drives the Lightning leg.
  // The merchant melt quote is created server-side; the customer-side mint
  // quote is ALSO created server-side and rendered into data-attrs above.
  // The browser is a pure consumer of these — there is no client-side
  // createMintQuoteBolt11 call, no localStorage cache. A page reload, a
  // browser switch, or a cleared cache can never lose the quote_id.
  // ------------------------------

  async function startMintQuoteWatcher(): Promise<void> {
    const mq = mintQuote;
    const wallet = await trustedWalletP;

    // The effective deadline is the tighter of the spot quote window
    // (server-enforced) and the mint quote's own expiry. If the mint quote
    // expires before the spot window, keep watching past mint expiry is
    // pointless — the BOLT11 is dead. If the spot expires first, keep
    // watching past spot is also pointless — the server will reject.
    const mintExpiryMs =
      data.mintQuote.expiry !== null ? data.mintQuote.expiry * 1000 : Infinity;
    const deadlineMs = () => Math.min(data.quoteExpiryMs || Infinity, mintExpiryMs);

    // Immediate state check on page load: if the customer paid before we got
    // here (e.g., they reloaded or returned to the page after closing it),
    // claim straight away rather than waiting for the WS subscription or
    // the next poll tick. One mint hit per page load — bounded by real
    // traffic. onceMintPaid only fires on a state TRANSITION, so without
    // this check a quote that's already PAID on arrival would otherwise
    // wait the full poll interval.
    try {
      const initial = await wallet.checkMintQuoteBolt11(mq.quote);
      if (initial.state === 'PAID') {
        void run(() => handleMintQuotePaid(mq));
        return;
      }
      if (initial.state === 'ISSUED') {
        // Proofs were minted in a prior session. If they were persisted to
        // localStorage before the page died (refresh-between-mint-and-melt
        // bug), resume the melt + claim path now. Otherwise this is the
        // genuine stranded case — different device, cleared storage, or
        // localStorage write failed at mint time — and only admin recovery
        // can complete the order.
        const stranded = loadStrandedProofs(mq.quote);
        if (stranded) {
          void run(() => handleMintQuotePaid(mq, stranded));
          return;
        }
        console.warn(
          'Mint quote already ISSUED but no local proofs; recovery requires admin intervention',
        );
        return;
      }
    } catch (e) {
      console.warn(
        'Initial mint quote check failed, continuing to WS / polling:',
        getErrorMessage(e),
      );
    }

    // Primary: WS subscription via cashu-ts. onceMintPaid auto-unsubscribes on
    // resolve/reject and accepts its own timeoutMs — no Promise.race needed.
    // We give the WS a few retries with short backoff before falling through
    // to polling: in practice the mint's WS is reliable on reconnect, and
    // bounding retries means the polling fallback still kicks in within
    // ~7s if the connection genuinely can't recover. A normal deadline
    // expiry is distinguished from a connection drop by checking remaining
    // budget — no retry once the quote window is essentially over.
    const wsPaid = async (): Promise<boolean> => {
      const WS_MAX_ATTEMPTS = 3;
      const WS_BACKOFFS_MS = [1000, 2000];
      for (let attempt = 0; attempt < WS_MAX_ATTEMPTS; attempt++) {
        const expiryMs = deadlineMs() - Date.now();
        if (expiryMs <= 0 || ac.signal.aborted) return false;
        try {
          await wallet.on.onceMintPaid(mq.quote, {
            signal: ac.signal,
            timeoutMs: expiryMs,
          });
          return true;
        } catch (e) {
          if (ac.signal.aborted) return false;
          const remaining = deadlineMs() - Date.now();
          const isLastAttempt = attempt === WS_MAX_ATTEMPTS - 1;
          if (remaining <= 1000 || isLastAttempt) {
            console.warn(
              'Cashu WS mint watcher exhausted, falling back to polling:',
              getErrorMessage(e),
            );
            return false;
          }
          console.warn(
            `Cashu WS dropped (attempt ${attempt + 1}/${WS_MAX_ATTEMPTS}), retrying in ${WS_BACKOFFS_MS[attempt]}ms:`,
            getErrorMessage(e),
          );
          await delay(WS_BACKOFFS_MS[attempt]);
          if (ac.signal.aborted) return false;
        }
      }
      return false;
    };

    // Fallback: slow polling of the mint. 12s × ~15min = ~75 requests, well
    // under typical mint rate limits.
    const pollPaid = async (): Promise<boolean> => {
      try {
        while (!ac.signal.aborted && Date.now() < deadlineMs()) {
          await delay(12_000);
          const q = await wallet.checkMintQuoteBolt11(mq.quote);
          if (q.state === 'PAID') return true;
        }
        return false;
      } catch {
        return false;
      }
    };

    const paid = (await wsPaid()) || (!ac.signal.aborted && (await pollPaid()));
    if (!paid) return;

    void run(() => handleMintQuotePaid(mq));
  }

  async function handleMintQuotePaid(
    mq: StoredMintQuote,
    knownProofs?: Proof[],
  ): Promise<void> {
    if (mintHandleP) return mintHandleP;

    mintHandleP = (async () => {
      setStatus(t('payment_received'));
      await delay(500);
      const wallet = await trustedWalletP;
      let mintedProofs: Proof[];
      if (knownProofs && knownProofs.length > 0) {
        // Resume path: proofs were minted in a prior session and persisted
        // to localStorage. Skip the mint call — it would fail anyway since
        // the quote is already ISSUED.
        mintedProofs = knownProofs;
      } else {
        mintedProofs = await wallet.mintProofsBolt11(data.expectedAmount, mq.quote);
        // Persist synchronously before any further await so a refresh
        // between here and meltProofsBolt11 can recover. The melt step
        // clears the snapshot once the proofs are spent at the mint.
        saveStrandedProofs(mq.quote, data.trustedMint, data.expectedAmount, mintedProofs);
      }
      await meltTrustedProofsToVendor(mintedProofs, wallet);
    })();

    try {
      await mintHandleP;
    } catch (e) {
      mintHandleP = null;
      throw e;
    }
  }

  // ------------------------------
  // Lightning leg melt — happens in the browser.
  // Each customer melts their own proofs against the merchant melt quote at
  // the trusted mint, so mint-facing load stays distributed.
  // ------------------------------

  async function meltTrustedProofsToVendor(
    proofs: Proof[],
    trustedWallet: Wallet,
  ): Promise<void> {
    const token = getEncodedToken({ mint: data.trustedMint, proofs, unit: 'sat' });
    let meltRes: MeltProofsResponse<MeltQuoteBolt11Response> | undefined;

    setStatus(t('paying_invoice'));

    let quote: MeltQuoteBolt11Response;
    try {
      quote = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
    } catch (e) {
      console.error(getErrorMessage(e));
      showRecovery(token);
      setStatus(t('payment_failed'), true);
      return;
    }

    // The melt may have completed in a prior session that died before
    // clearing the stranded-proof snapshot — typical refresh loop after a
    // successful payment. Re-attempting meltProofsBolt11 against a PAID
    // quote returns "melt quote is not unpaid: paid" and we'd surface a
    // recovery UI for proofs the mint has already spent. Instead, drop
    // the stale snapshot and hand the preimage straight to claim so the
    // customer redirects to thank-you.
    if (quote.state === MeltQuoteState.PAID) {
      clearStrandedProofs(data.mintQuote.id);
      const paidPreimage =
        typeof (quote as any).payment_preimage === 'string'
          ? (quote as any).payment_preimage
          : '';
      setStatus(t('confirming_payment'));
      void claimMeltPaid(paidPreimage);
      return;
    }

    try {
      meltRes = await trustedWallet.meltProofsBolt11(quote, proofs);
    } catch (e) {
      console.error(getErrorMessage(e));
      showRecovery(token);
      setStatus(t('payment_failed'), true);
      return;
    }

    // Proofs are spent at the mint — recovery snapshot is now stale and
    // would only confuse a future reload of this same order. Safe to drop
    // even if the subsequent claim POST never reaches the server; the
    // background pollOrderStatus will catch the PAID transition.
    clearStrandedProofs(data.mintQuote.id);

    const changeProofs = Array.isArray(meltRes?.change) ? meltRes.change : [];
    void saveProofs(changeProofs, trustedWallet);

    setStatus(t('confirming_payment'));

    // Tell the server the melt succeeded so it can mark the order paid.
    // Preimage when available lets the server verify cryptographically with
    // zero mint round-trips; otherwise the server falls back to a single
    // mint call. Either way the polling endpoint will see is_paid() next
    // tick — but we also redirect immediately on a PAID response so the
    // customer doesn't wait the next poll interval.
    const preimage = (meltRes as any)?.quote?.payment_preimage;
    void claimMeltPaid(typeof preimage === 'string' ? preimage : '');
  }

  async function claimMeltPaid(preimage: string): Promise<void> {
    const restRoot = String(window.cashu_wc?.rest_root ?? '');
    const route = String(window.cashu_wc?.claim_route ?? '');
    if (!restRoot || !route) return;

    const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        signal: ac.signal,
        body: JSON.stringify({
          order_id: data.orderId,
          order_key: data.orderKey,
          preimage,
        }),
      });
      const json = (await res.json()) as ConfirmPaidResponse;
      if (json?.state === 'PAID') {
        doConfettiBomb();
        await delay(2000);
        window.location.assign(String(json.redirect ?? data.returnUrl));
      }
    } catch (e) {
      if (ac.signal.aborted) return;
      // Background pollOrderStatus will catch the settlement on the next tick.
      console.warn('Claim POST failed, falling back to poll:', getErrorMessage(e));
    }
  }

  // ------------------------------
  // Order Status - drives the redirect for either settlement leg.
  // ------------------------------

  async function checkOrderStatus(): Promise<ConfirmPaidResponse | null> {
    const restRoot = String(window.cashu_wc?.rest_root ?? '');
    const route = String(window.cashu_wc?.confirm_route ?? '');
    if (!restRoot || !route) return null;

    const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

    const payload: any = {
      order_id: data.orderId,
      order_key: data.orderKey,
      quote_id: data.quoteId,
    };

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        signal: ac.signal,
        body: JSON.stringify(payload),
      });
      const json = (await res.json()) as ConfirmPaidResponse;

      // Server is the authoritative source for the spot-quote expiry. The
      // value rendered into data-spot-quote-expiry at page load can drift
      // if setup ran again (rare but possible after a stale-quote
      // rotation). Trust each fresh response — `json.expiry` is unix
      // seconds; convert to ms before comparing with Date.now().
      if (typeof json?.expiry === 'number' && json.expiry > 0) {
        data.quoteExpiryMs = json.expiry * 1000;
      }

      if (json?.state === 'PAID') {
        // Server reached PAID independently — clear any stranded-proof
        // snapshot so a future reload of this order doesn't try to
        // re-melt already-spent proofs.
        clearStrandedProofs(data.mintQuote.id);
        doConfettiBomb();
        await delay(2000);
        window.location.assign(String(json.redirect ?? data.returnUrl));
        return json;
      }

      if (json?.state === 'EXPIRED') {
        // Quote window closed: snapshot can't help anymore and would only
        // distract future polls.
        clearStrandedProofs(data.mintQuote.id);
        setStatus(t('invoice_expired'), true);
        await delay(2000);
        window.location.assign(String(data.returnUrl));
        return json;
      }
      if (json?.state === 'PENDING') {
        // Server saw the wallet's POST go through but the mint is still
        // routing LN. Polling continues; show progress so the customer
        // knows it's working, not stuck.
        setStatus(t('settling_at_mint'));
      }
      if (json?.expiry) {
        const msg = t('invoice_expires_in', formatCountdown(json.expiry));
        const seconds = json.expiry - Date.now() / 1000;
        if (seconds < 300) {
          setStatus(msg, seconds < 60);
        }
      }

      return json ?? null;
    } catch {
      return null;
    }
  }

  let pollOrderStatusRunning = false;
  async function pollOrderStatus(): Promise<void> {
    if (pollOrderStatusRunning) return;
    pollOrderStatusRunning = true;
    try {
      if (ac.signal.aborted || Date.now() > data.quoteExpiryMs) {
        window.location.assign(String(data.returnUrl));
        return;
      }
      while (!ac.signal.aborted && Date.now() <= data.quoteExpiryMs) {
        await delay(5000);
        const r = await run(() => checkOrderStatus());
        if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
      }
      await delay(500);
      await run(() => checkOrderStatus());
    } finally {
      pollOrderStatusRunning = false;
    }
  }

  // Continuous background poll of confirm-melt-quote: catches both legs.
  // The cashu leg has no other signal here (server marks paid on POST receipt);
  // the lightning leg uses this after the client-side melt completes.
  void pollOrderStatus();

  function formatCountdown(
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
});
