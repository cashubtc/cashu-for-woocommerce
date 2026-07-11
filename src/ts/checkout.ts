import {
  getEncodedToken,
  Wallet,
  sumProofs,
  Proof,
  MeltQuoteState,
  MeltQuoteBolt11Response,
  MeltProofsResponse,
  PaymentRequest,
  PaymentRequestTransportType,
} from '@cashu/cashu-ts';
import qrcode from 'qrcode-generator';
import { copyTextToClipboard, doConfettiBomb, delay, getErrorMessage } from './utils';
import {
  CHANGE_PAYLOAD_KEY,
  actionsForMeltOutcome,
  clearStrandedProofs,
  createSerialRunner,
  deleteJson,
  deriveMeltFailureBranch,
  deriveWalletSeed,
  extractPaymentPreimage,
  loadStrandedProofs,
  MINT_POLL_INTERVALS_MS,
  type MeltAction,
  type MeltOutcome,
  qrHintKeyForMode,
  type QrMode,
  readRootData,
  rememberChangeItem,
  type RootData,
  saveStrandedProofs,
  seedFingerprint,
  selectPollIntervalMs,
  sweepStaleStrandedProofs,
} from './helpers';
import {
  checkOrderStatus as checkOrderStatusDispatch,
  claimMeltPaid as claimMeltPaidDispatch,
  type ConfirmPaidResponse,
  type DispatcherDeps,
} from './dispatchers';
import { createWalletGetter, tryRestore } from './wallet';

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

// ------------------------------
// Helpers
// ------------------------------

const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

const getWalletCached = createWalletGetter();

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
  const $qrHint = $scope.find('[data-cashu-qr-hint]');
  let recoveryToken = '';
  const setStatus = (msg: string, isError: boolean = false) => {
    const color = isError ? 'var(--cashu-warning)' : 'var(--cashu-status)';
    $status.text(msg).css('background-color', color);
  };

  let data: RootData;
  try {
    data = readRootData({ data: (k: string) => $root.data(k) });
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

  // Serial runner: queues async work onto a single Promise chain so concurrent
  // callers (WS, poll, page-load) can't interleave a mint/melt mid-flight.
  // The chain itself never rejects — fn errors are surfaced via setStatus.
  const run = createSerialRunner(setStatus);
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
  // The mint quote is server-authoritative (rendered into data-attrs by
  // the receipt page). We read `data.mintQuote.*` directly throughout; no
  // local copy, no localStorage caching, no race against a page refresh.

  // Best-effort clean up legacy localStorage from earlier versions of the
  // plugin (cashu_wc_mq, cashu_wc_recovery) plus the previous order's
  // change snapshot — change is deliberately ephemeral.
  deleteJson('cashu_wc_mq');
  deleteJson('cashu_wc_recovery');
  deleteJson(CHANGE_PAYLOAD_KEY);

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
    applyQrHintForMode(mode);
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

  // Per-tab copy hint under the QR, mirroring applyQrIconForMode.
  function applyQrHintForMode(mode: QrMode): void {
    $qrHint.text(t(qrHintKeyForMode(mode)));
  }

  // Apply the default mode's overlay + hint immediately so the unified tab
  // renders correctly from first paint.
  applyQrIconForMode(currentMode);
  applyQrHintForMode(currentMode);

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
    // is why renderQr uppercases the BIP-321 / LIGHTNING URIs. Scalable SVG
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

    // Copy current tab's paste-friendly text on click. Feedback is a green
    // tint + checkmark overlay on the QR itself; the status line is left
    // alone and keeps showing waiting/expiry state throughout.
    const $qrWrap = $qr.parent();
    const qrWrapEl = $qrWrap.get(0) as HTMLElement | undefined;
    $qrWrap.off('click').on('click', async () => {
      const txt = copyTexts[currentMode];
      if (!txt) return;
      copyTextToClipboard(txt);
      $qrWrap.removeClass('copied');
      if (qrWrapEl) void qrWrapEl.offsetWidth; // restart the CSS animation on rapid re-copy
      $qrWrap.addClass('copied');
      await delay(1600);
      $qrWrap.removeClass('copied');
    });
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
    rememberChangeItem({
      mint: wallet.mint.mintUrl,
      token: tokenStr,
      amount: changeAmt,
      kind: t('change_from_network'),
      dust: changeAmt <= changeFees,
    });
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
        // Compare against the QUOTE's amount, not expectedAmount — after a
        // spot re-quote the two can diverge, and the proofs the mint issued
        // can only ever sum to the quote's amount.
        setStatus(t('recovering_proofs'));
        const restored = await tryRestore(wallet, data.mintQuote.amount);
        if (
          restored.length > 0 &&
          sumProofs(restored).toNumber() >= data.mintQuote.amount
        ) {
          // Persist immediately so a subsequent reload uses the fast path.
          saveStrandedProofs(
            data.mintQuote.id,
            data.trustedMint,
            data.mintQuote.amount,
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

    // Fallback: slow polling of the mint, backing off as the wait grows.
    // The deadline is live: a slid spot window (updateQuoteExpiry) extends
    // it between iterations with no reload. The try/catch sits around the
    // single probe, not the whole loop: one thrown check counts toward the
    // backoff streak and the loop continues, rather than a single failure
    // permanently ending the poll while the deadline may extend for hours.
    const pollPaid = async (): Promise<boolean> => {
      let streak = 0;
      while (!ac.signal.aborted && Date.now() < deadlineMs()) {
        await delay(selectPollIntervalMs(streak, MINT_POLL_INTERVALS_MS));
        streak += 1;
        try {
          const q = await wallet.checkMintQuoteBolt11(data.mintQuote.id);
          if (q.state === 'PAID') return true;
        } catch (e) {
          console.warn('Mint quote poll failed, retrying:', getErrorMessage(e));
        }
      }
      return false;
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
        // Mint the QUOTE's amount, not expectedAmount. They're identical in
        // the normal flow, but diverge when a paid mint quote is kept across
        // a spot re-quote (customer paid, melt didn't finish, price moved):
        // the mint only ever signs outputs summing to the quote's amount, so
        // requesting expectedAmount would be rejected and strand the
        // customer's already-paid quote. Minting the quote amount succeeds;
        // if it then can't cover the new melt total, the melt-failure path
        // surfaces a recovery token instead of locking the funds.
        mintedProofs = await wallet.mintProofsBolt11(
          data.mintQuote.amount,
          data.mintQuote.id,
        );
        // Persist synchronously before any further await so a refresh
        // between here and meltProofsBolt11 can recover. The melt step
        // clears the snapshot once the proofs are spent at the mint.
        saveStrandedProofs(
          data.mintQuote.id,
          data.trustedMint,
          data.mintQuote.amount,
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
    setStatus(t('paying_invoice'));
    const outcome = await classifyMeltOutcome(proofs, trustedWallet, token);
    for (const a of actionsForMeltOutcome(outcome)) {
      await dispatchMeltAction(a, trustedWallet);
    }
  }

  // Async classifier: walks the mint sequence (entry quote check → melt
  // attempt → post-throw probe) and pins the result to one of six discrete
  // outcomes. All the awaits live here; the projection to an action sequence
  // (actionsForMeltOutcome) is pure logic in helpers.ts and exhaustively
  // unit-tested in melt-actions.test.ts.
  async function classifyMeltOutcome(
    proofs: Proof[],
    trustedWallet: Wallet,
    encodedToken: string,
  ): Promise<MeltOutcome> {
    let initialQuote: MeltQuoteBolt11Response;
    try {
      initialQuote = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
    } catch (e) {
      console.error(getErrorMessage(e));
      return { kind: 'entry_check_threw', encodedToken };
    }

    if (initialQuote.state === MeltQuoteState.PAID) {
      return {
        kind: 'entry_paid_stale_snapshot',
        preimage: extractPaymentPreimage(initialQuote),
        mintQuoteId: data.mintQuote.id,
      };
    }

    let meltRes: MeltProofsResponse<MeltQuoteBolt11Response> | undefined;
    try {
      meltRes = await trustedWallet.meltProofsBolt11(initialQuote, proofs);
    } catch (e) {
      console.warn(
        'meltProofsBolt11 threw, re-checking quote state:',
        getErrorMessage(e),
      );
      let postState: MeltQuoteState | null = null;
      try {
        const recheck = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
        postState = recheck.state;
      } catch {
        // treat as unknown
      }
      switch (deriveMeltFailureBranch(postState)) {
        case 'paid_inputs_spent':
          return {
            kind: 'melt_threw_inputs_spent',
            mintQuoteId: data.mintQuote.id,
          };
        case 'unpaid_inputs_safe':
          return { kind: 'melt_threw_inputs_safe', encodedToken };
        case 'unknown_let_server_probe':
          return { kind: 'melt_threw_state_unknown' };
      }
    }

    const changeProofs = Array.isArray(meltRes?.change) ? meltRes.change : [];
    return {
      kind: 'melt_succeeded',
      preimage: extractPaymentPreimage(meltRes),
      changeProofs,
      mintQuoteId: data.mintQuote.id,
    };
  }

  // Action executor: closure-coupled to the wallet handle, setStatus, t, and
  // claimMeltPaid so the action list itself stays pure data. The background
  // saves (saveProofs, claimMeltPaid) are `void`-fired — they post to
  // localStorage / our REST without blocking the next action.
  async function dispatchMeltAction(a: MeltAction, trustedWallet: Wallet): Promise<void> {
    switch (a.type) {
      case 'clearStranded':
        clearStrandedProofs(a.mintQuoteId);
        return;
      case 'restoreAndSaveChange': {
        const restored = await tryRestore(trustedWallet);
        void saveProofs(restored, trustedWallet);
        return;
      }
      case 'saveResponseChange':
        void saveProofs(a.proofs, trustedWallet);
        return;
      case 'showRecovery':
        showRecovery(a.token);
        return;
      case 'setStatus':
        setStatus(t(a.key), a.isError);
        return;
      case 'claim':
        void claimMeltPaid(a.preimage);
        return;
    }
  }

  // Single-flight gate over the success branch shared by claimMeltPaid()
  // and checkOrderStatus(). Both endpoints are server-idempotent and either
  // can win the race on a Lightning settlement, but only one needs to fire
  // confetti + redirect. Lives on a state object so the dispatchers
  // (extracted to ./dispatchers) can mutate it through their deps handle.
  const dispatcherState = { finalised: false };

  const dispatcherDeps: DispatcherDeps = {
    config: {
      restRoot: String(window.cashu_wc?.rest_root ?? ''),
      claimRoute: String(window.cashu_wc?.claim_route ?? ''),
      confirmRoute: String(window.cashu_wc?.confirm_route ?? ''),
    },
    data,
    state: dispatcherState,
    signal: ac.signal,
    nowSeconds: () => Date.now() / 1000,
    setStatus,
    t,
    delay,
    doConfettiBomb,
    clearStrandedProofs,
    redirect: (url: string) => window.location.assign(url),
  };

  function claimMeltPaid(preimage: string): Promise<void> {
    return claimMeltPaidDispatch(dispatcherDeps, preimage);
  }

  // ------------------------------
  // Order Status - drives the redirect for either settlement leg.
  // ------------------------------

  function checkOrderStatus(): Promise<ConfirmPaidResponse | null> {
    return checkOrderStatusDispatch(dispatcherDeps);
  }

  let pollOrderStatusRunning = false;
  // Poll cadence: tight on fresh quotes (5s — catches quick LN settles fast),
  // backs off as the server keeps returning PENDING. Each PENDING response
  // shifts the next delay one step along POLL_INTERVALS_MS, capped. Drops
  // mint-side amplification by ~3-6x on a long-routing LN payment without
  // changing the no-PENDING UX. Resets on any non-PENDING response.
  const POLL_INTERVALS_MS = [5_000, 15_000, 30_000];
  // Terminates only on abort, PAID, or EXPIRED (both carry their own
  // redirect via checkOrderStatus). The local deadline alone never ends the
  // loop: the server can slide the window (updateQuoteExpiry) between polls,
  // and this is the NUT-18 leg's only completion signal — dying here would
  // strand the tab on "waiting" forever.
  async function pollOrderStatus(): Promise<void> {
    if (pollOrderStatusRunning) return;
    pollOrderStatusRunning = true;
    try {
      let pendingStreak = 0;
      while (!ac.signal.aborted) {
        if (Date.now() > data.quoteExpiryMs) {
          // Local deadline passed. One more check distinguishes a genuine
          // expiry from a server-side slide that happened since our last
          // poll: only a slide that actually moves the deadline forward
          // restarts the loop, everything else falls back to returnUrl.
          const expiryBefore = data.quoteExpiryMs;
          const r = await run(() => checkOrderStatus());
          if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
          if (data.quoteExpiryMs > expiryBefore && Date.now() <= data.quoteExpiryMs) {
            pendingStreak = r?.state === 'PENDING' ? pendingStreak + 1 : 0;
            continue;
          }
          window.location.assign(String(data.returnUrl));
          return;
        }
        await delay(selectPollIntervalMs(pendingStreak, POLL_INTERVALS_MS));
        const r = await run(() => checkOrderStatus());
        if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
        pendingStreak = r?.state === 'PENDING' ? pendingStreak + 1 : 0;
      }
    } finally {
      pollOrderStatusRunning = false;
    }
  }

  // Continuous background poll of confirm-melt-quote: catches both legs.
  // The cashu leg has no other signal here (server marks paid on POST receipt);
  // the lightning leg uses this after the client-side melt completes.
  void pollOrderStatus();
});
