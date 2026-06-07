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
import qrcode from 'qrcode-generator';
import { copyTextToClipboard, doConfettiBomb, delay, getErrorMessage } from './utils';
import {
  ChangeItem,
  clearStrandedProofs,
  deleteJson,
  deriveWalletSeed,
  formatCountdown,
  loadChangePayload,
  loadStrandedProofs,
  saveJson,
  saveStrandedProofs,
  seedFingerprint,
  sweepStaleStrandedProofs,
} from './helpers';

// ------------------------------
// Types
// ------------------------------

type CashuWindow = Window & {
  cashu_wc?: {
    rest_root?: string;
    confirm_route?: string;
    claim_route?: string;
    symbol: string;
    qr_icons?: { cashu?: string; lightning?: string };
    i18n?: Record<string, string>;
  };
};
declare const window: CashuWindow;

declare const wp: { i18n: { sprintf: (format: string, ...args: any[]) => string } };

type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

type ConfirmPaidResponse = {
  ok?: boolean;
  state?: MeltQuoteState | 'EXPIRED';
  redirect?: string;
  message?: string;
  expiry?: number | null;
  // Unix seconds of the most recent failed payment attempt. Server returns
  // this on UNPAID responses so the receipt page can show a "previous
  // attempt didn't reach the mint, please try again" banner instead of
  // silently reverting to the default "Waiting for payment" placeholder.
  // Null on fresh orders that have never had a marker-drop event.
  last_attempt?: number | null;
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
  defaultTab: QrMode; // initial active tab; server-side default-path resolution applied
};

// ------------------------------
// Helpers
// ------------------------------

const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

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
async function tryRestore(wallet: Wallet, targetAmount?: number): Promise<Proof[]> {
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

// Wallet cache: bounded so a long-lived tab doesn't reuse a Wallet with stale
// keyset state. Mint keyset rotations are rare but possible, and a stale
// wallet would silently produce proofs the mint rejects on next use.
// The cache key now incorporates the seed fingerprint so two orders against
// the same mint never share a Wallet (different seeds = different
// deterministic counters; sharing a Wallet across seeds is a correctness bug).
type CachedWallet = { promise: Promise<Wallet>; createdAt: number };
const WALLET_CACHE_TTL_MS = 10 * 60 * 1000; // 10 minutes
const walletCache = new Map<string, CachedWallet>();

function getWalletCached(
  mintUrl: string,
  unit: CurrencyUnit,
  seed: Uint8Array,
  fingerprint: string,
): Promise<Wallet> {
  const key = `${String(mintUrl).replace(/\/+$/, '')}|${unit}|${fingerprint}`;
  const existing = walletCache.get(key);
  if (existing && Date.now() - existing.createdAt < WALLET_CACHE_TTL_MS) {
    return existing.promise;
  }
  if (existing) walletCache.delete(key);
  const promise = (async () => {
    const w = new Wallet(mintUrl, {
      unit,
      bip39seed: seed,
      logger: new ConsoleLogger('debug'),
    });
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

  const rawDefaultTab = String($root.data('default-tab') ?? 'unified');
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

// LocalStorage helpers, stranded-proof persistence, change-payload
// load/save, and the wallet-seed derivation now live in `./helpers` so they
// can be unit-tested without spinning up the jQuery scope or wp-env.

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
  const $qrIcon = $scope.find('[data-cashu-qr-icon]');
  const $qrIconImg = $qrIcon.find('img');
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
  // Copy-on-click target per tab. Differs from qrTexts on the lightning tab:
  // the QR carries `LIGHTNING:LNBC1...` so a camera scan OS-routes into a
  // wallet; the clipboard gets raw `lnbc1...` so paste-into-wallet matches
  // every wallet's "paste invoice" field shape.
  let copyTexts: Record<QrMode, string> = { unified: '', cashu: '', lightning: '' };
  let currentMode: QrMode = data.defaultTab;

  let chain: Promise<any> = Promise.resolve();
  let mintHandleP: Promise<void> | null = null;
  // Seed is derived per-order from data-attrs already on the page. No
  // persistence, no async, no browser-feature dependency (sha512 is from
  // @noble/hashes, already a direct dep of cashu-ts). Cache-key fingerprint
  // is the first 8 seed bytes as hex (so two orders against the same mint
  // never share a Wallet — different seeds → different deterministic counter
  // state → fatal counter collision).
  const walletSeed = deriveWalletSeed(data.orderKey, data.mintQuote.id);
  const walletSeedFp = seedFingerprint(walletSeed);
  const trustedWalletP = getWalletCached(
    data.trustedMint,
    'sat',
    walletSeed,
    walletSeedFp,
  );
  const ls = {
    change: 'cashu_wc_change',
  };

  // The mint quote is server-authoritative (rendered into data-attrs by
  // the receipt page). We read `data.mintQuote.*` directly throughout; no
  // local copy, no localStorage caching, no race against a page refresh.

  // Best-effort clean up legacy localStorage from earlier versions of the
  // plugin; harmless if nothing is there. deleteJson already swallows.
  deleteJson('cashu_wc_mq');
  deleteJson('cashu_wc_recovery');
  deleteJson(ls.change);

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
    applyQrIconForMode(mode);
    if (qrTexts[mode]) drawQr(qrTexts[mode]);
  });

  // Per-tab centre-icon overlay. Unified hides entirely — the BIP-321 payload
  // carries more bytes (less error-correction headroom for an opaque cutout)
  // and is dual-protocol, so branding it with either logo would mislead.
  // Cashu/Lightning tabs each show their own protocol's mark.
  function applyQrIconForMode(mode: QrMode): void {
    if (!$qrIcon.length) return;
    if (mode === 'unified') {
      $qrIcon.prop('hidden', true);
      return;
    }
    const icons = window.cashu_wc?.qr_icons ?? {};
    const src = mode === 'lightning' ? icons.lightning : icons.cashu;
    if (!src) {
      $qrIcon.prop('hidden', true);
      return;
    }
    if ($qrIconImg.attr('src') !== src) {
      $qrIconImg.attr('src', src);
    }
    $qrIcon.prop('hidden', false);
  }
  // Apply the default mode's overlay immediately so the unified tab renders
  // without an icon from first paint.
  applyQrIconForMode(currentMode);

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
    $recovery.prop('hidden', false);
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
    if (!el) return;
    // Type 0 = auto-pick smallest QR version; 'Q' = ~25% error correction
    // (needed so the centre-icon overlay doesn't break scanning). addData()
    // auto-detects Alphanumeric mode for [A-Z0-9 $%*+\-./:] payloads — which
    // is why we uppercase the BIP-321 / LIGHTNING URIs above. Scalable SVG
    // renders crisp at any container size.
    const qr = qrcode(0, 'Q');
    qr.addData(text);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
  }

  async function renderQr(): Promise<void> {
    // Uppercase the BOLT11 (and scheme) so the QR encoder picks alphanumeric
    // mode (5.5 bits/char) instead of byte mode (8 bits/char) — same payload,
    // denser QR, easier scan. bech32 forbids mixed case so the whole token
    // must be one case; bolt11 is case-insensitive, so all-upper is valid.
    const lightningInvoice = data.mintQuote.request;
    const lightningUri = 'LIGHTNING:' + lightningInvoice.toUpperCase();

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

    // BIP-321 URI: fully uppercase to match CDK reference (the scheme and
    // query keys are case-insensitive per BIP-321; BOLT11 + CREQB are bech32
    // / bech32m so case-insensitive too). Same payload, denser QR potential,
    // identical semantics. cashu-ts already returns CREQB uppercase; the
    // mint quote request is lowercase from the mint, so uppercase it here.
    const unifiedUri =
      'BITCOIN:?LIGHTNING=' + data.mintQuote.request.toUpperCase() + '&CREQ=' + creq;

    qrTexts = {
      unified: unifiedUri,
      cashu: creq,
      lightning: lightningUri,
    };
    copyTexts = {
      // Unified (BIP-321) is intentionally a URI for both QR and copy —
      // paste targets that understand BIP-321 want the scheme.
      unified: unifiedUri,
      cashu: creq,
      // Raw invoice for paste; QR keeps the LIGHTNING: scheme.
      lightning: lightningInvoice,
    };

    drawQr(qrTexts[currentMode]);

    // Copy current tab's paste-friendly text on click.
    const $qrWrap = $qr.parent();
    $qrWrap.off('click').on('click', async () => {
      const txt = copyTexts[currentMode];
      if (!txt) return;
      copyTextToClipboard(txt);
      setStatus(t('copied'));
      await delay(500);
      setStatus(t('waiting_for_payment'));
    });
  }

  // Serialize async work against a single chain so concurrent callers
  // (WS, poll, page-load) can't interleave a mint/melt mid-flight.
  // Errors are surfaced to the status line; the chain itself is never
  // rejected so subsequent run() calls keep flowing.
  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    const p = chain.then(fn).catch((e) => {
      setStatus(getErrorMessage(e), true);
      return undefined as unknown as T;
    });
    chain = p.then(() => undefined);
    return p;
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
    // saveProofs is only ever called with the trusted mint's wallet, so the
    // "change from token" path (mint mismatch) cannot fire — that path
    // existed when the PR-only refactor still had a token-input flow.
    rememberChangeItem({
      mint: wallet.mint.mintUrl,
      token: tokenStr,
      amount: changeAmt,
      kind: t('change_from_network'),
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
      const initial = await wallet.checkMintQuoteBolt11(data.mintQuote.id);
      if (initial.state === 'PAID') {
        void run(() => handleMintQuotePaid());
        return;
      }
      if (initial.state === 'ISSUED') {
        // Fast path: proofs were persisted to localStorage by a prior session.
        const stranded = loadStrandedProofs(data.mintQuote.id);
        if (stranded) {
          void run(() => handleMintQuotePaid(stranded));
          return;
        }
        // Slow path: NUT-09 restore via the deterministic seed. The mint
        // returns any signatures it has against blinded outputs we'd have
        // derived for this seed × keyset, which we unblind into Proofs.
        setStatus(t('recovering_proofs'));
        const restored = await tryRestore(wallet, data.expectedAmount);
        if (
          restored.length > 0 &&
          sumProofs(restored).toNumber() >= data.expectedAmount
        ) {
          // Persist immediately so a subsequent reload uses the fast path.
          saveStrandedProofs(
            data.mintQuote.id,
            data.trustedMint,
            data.expectedAmount,
            restored,
          );
          void run(() => handleMintQuotePaid(restored));
          return;
        }
        console.warn('Mint quote ISSUED but restore returned no usable proofs');
        setStatus(t('recovery_failed_contact'), true);
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
          await wallet.on.onceMintPaid(data.mintQuote.id, {
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
          const q = await wallet.checkMintQuoteBolt11(data.mintQuote.id);
          if (q.state === 'PAID') return true;
        }
        return false;
      } catch {
        return false;
      }
    };

    const paid = (await wsPaid()) || (!ac.signal.aborted && (await pollPaid()));
    if (!paid) return;

    void run(() => handleMintQuotePaid());
  }

  async function handleMintQuotePaid(knownProofs?: Proof[]): Promise<void> {
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
        mintedProofs = await wallet.mintProofsBolt11(
          data.expectedAmount,
          data.mintQuote.id,
        );
        // Persist synchronously before any further await so a refresh
        // between here and meltProofsBolt11 can recover. The melt step
        // clears the snapshot once the proofs are spent at the mint.
        saveStrandedProofs(
          data.mintQuote.id,
          data.trustedMint,
          data.expectedAmount,
          mintedProofs,
        );
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
      // The melt completed in a prior session; if the response carried
      // change-proofs they would have only existed in JS heap until
      // saveProofs ran. Death between those two points orphans the change.
      // One NUT-09 sweep on reload recovers any orphans into the existing
      // change-display path. No-op when there were no change-proofs.
      const restoredChange = await tryRestore(trustedWallet);
      void saveProofs(restoredChange, trustedWallet);
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
      console.warn(
        'meltProofsBolt11 threw, re-checking quote state:',
        getErrorMessage(e),
      );
      // The mint may have spent the inputs and dropped the response.
      // Probe state before showing the recovery UI — otherwise we'd offer
      // the customer a token containing already-spent proofs.
      let postState: MeltQuoteState | null = null;
      try {
        const recheck = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
        postState = recheck.state;
      } catch {
        // treat as unknown
      }
      if (postState === MeltQuoteState.PAID) {
        // Inputs are spent; the input token would be worthless. Recover
        // any change-proofs via NUT-09 and let the server finalise.
        clearStrandedProofs(data.mintQuote.id);
        const restoredChange = await tryRestore(trustedWallet);
        void saveProofs(restoredChange, trustedWallet);
        setStatus(t('confirming_payment'));
        void claimMeltPaid('');
        return;
      }
      if (postState === MeltQuoteState.UNPAID) {
        // Inputs were never spent — input token is a valid recovery.
        showRecovery(token);
        setStatus(t('payment_failed'), true);
        return;
      }
      // Unknown / pending: cannot safely tell the customer whether their
      // inputs are spent. Surface a "reconciling" status and notify the
      // server — claim_melt_quote will probe the mint itself, and when
      // the mint reports PENDING it writes _cashu_melt_pending_quote_id
      // server-side so the MeltReconciler cron picks the order up if
      // the customer closes their tab. This closes the LN-leg gap that
      // previously left the order unreconcilable.
      setStatus(t('reconciling_with_mint'), true);
      void claimMeltPaid('');
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

  // Single-flight gate over the success branch shared by claimMeltPaid()
  // and checkOrderStatus(). Both endpoints are server-idempotent and either
  // can win the race on a Lightning settlement, but only one needs to fire
  // confetti + redirect.
  let finalised = false;

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
        if (finalised) return;
        finalised = true;
        setStatus(t('payment_confirmed'));
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
        if (finalised) return json;
        finalised = true;
        setStatus(t('payment_confirmed'));
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
      if (
        json?.state === 'UNPAID' &&
        typeof json.last_attempt === 'number' &&
        json.last_attempt > 0
      ) {
        // The server dropped a pending-melt marker because the mint
        // returned UNPAID — a previous payment attempt was made but the
        // mint never received the proofs. Surface a banner so the
        // customer knows they need to retry (likely with a wallet
        // reclaim first) instead of seeing the default "Waiting for
        // payment" and assuming the page is broken.
        setStatus(t('previous_attempt_failed'), true);
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
  // Poll cadence: tight on fresh quotes (5s — catches quick LN settles fast),
  // backs off as the server keeps returning PENDING. Each PENDING response
  // shifts the next delay one step along POLL_INTERVALS_MS, capped. Drops
  // mint-side amplification by ~3-6x on a long-routing LN payment without
  // changing the no-PENDING UX. Resets on any non-PENDING response.
  const POLL_INTERVALS_MS = [5_000, 15_000, 30_000];
  async function pollOrderStatus(): Promise<void> {
    if (pollOrderStatusRunning) return;
    pollOrderStatusRunning = true;
    try {
      if (ac.signal.aborted || Date.now() > data.quoteExpiryMs) {
        window.location.assign(String(data.returnUrl));
        return;
      }
      let pendingStreak = 0;
      while (!ac.signal.aborted && Date.now() <= data.quoteExpiryMs) {
        const step = Math.min(pendingStreak, POLL_INTERVALS_MS.length - 1);
        await delay(POLL_INTERVALS_MS[step]);
        const r = await run(() => checkOrderStatus());
        if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
        pendingStreak = r?.state === 'PENDING' ? pendingStreak + 1 : 0;
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
});
