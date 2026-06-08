# Changelog

## Unreleased

### Bug Fixes

- handle PENDING melts gracefully, guard melt-quote rotation
- harden quote-rotation, race-safety and mint-routing on cashu orders
- address remaining MEDIUM/LOW code-review items (#11-23)
- retry mint-quote WS subscription before falling back to poll
- second-pass review — mint routing, rate limits, lock contention, preimage verification
- guard preimageMatches against odd-length hex
- recover stranded ISSUED proofs from localStorage
- skip melt if quote already PAID — avoid recovery UI on refresh-loop
- uppercase LIGHTNING QR + raw-invoice copy
- show 'Payment confirmed!' before redirect
- correct path-validation hook plan + drop cashu_enabled from getConfig
- sanitize_default_path reads $_POST to see in-flight cashu_paths
- localize admin UX-assist labels via wp_localize_script
- align array double-arrow in wp_localize_script call
- use desc (not label) for path checkbox text — WC checkbox renderer ignores label
- store cashu_paths as yes/no strings so checkbox renders correctly
- silence WC Blocks + jQuery migrate console warnings
- dump no-dev autoload before zipping the plugin
- drop vendor/ from zip, hand-roll PSR-4 autoload
- gate the success branch behind a single-flight flag
- preserve marker through transient mint outages
- NUT-09 restore stranded mint-quote proofs on reload
- recover orphaned change-proofs on melt-quote reload
- re-probe melt quote state on meltProofsBolt11 failure
- clarify recovery copy and normalise ellipsis
- advance wallet counters after NUT-09 restore
- assorted order-state and admin fixes
- render retry-melt button as link, not nested form
- add required meta.author field
- clear WC activation-redirect transient so landingPage actually lands
- pre-enable Cashu gateway so settings work end-to-end


### Features

- admin recovery meta-box, never rotate a paid mint quote
- per-tab QR centre-icon overlay
- add CashuPaths helper for path bitmap + default resolution
- one-time auto-flip gateway enable from legacy cashu_enabled
- rework Cashu Settings tab — paths + default, drop enable
- validate cashu_paths + cashu_default_path on save
- render only enabled payment tabs + seed default
- seed currentMode from server-rendered default tab
- client-side UX assist for path gating + default repopulation
- probe mint NUT-06 on save, require BOLT11/sat for both NUT-04 and NUT-05
- adopt official Cashu chip icon + rewrite gateway description
- wipe global plugin state on plugin deletion
- show gateway icon in WC Blocks checkout
- pre-stage pending marker before mint melt call
- reconcile mint state on melt failure
- add MeltReconciler cron handler
- schedule MeltReconciler cron hook on activation
- surface pending-melt reconciliation status
- add deterministic wallet seed derivation
- seed cashu-ts wallet per-order for NUT-09 restore
- add tryRestore NUT-09 recovery helper
- add recovery-flow status strings
- surface previous-attempt-failed banner instead of silent reset
- close LN-leg in-flight gap via server marker write on PENDING
- refresh change-panel copy and add no-wallet onboarding
- LNURL probe on lightning_address + retry button on stuck orders


### Performance

- raise mint state cache TTL and back off on rate-limited responses
- back off pollOrderStatus on consecutive PENDING responses
- also cache mint state probe on receipt page render


### Refactoring

- remove unused fee estimation
- BIP-321 QR + NUT-18 receiver, server-side mint quote, cashu-ts v4
- lift mint-URL normaliser; fix internal admin-mint compares
- uppercase BIP-321 URI + swap to qrcode-generator
- strict path-bitmap checks + idempotency test
- drop redundant cashu_enabled check from is_available()
- rename seed domain string to cashu_wc_wallet_seed_v1
- drop vestigial user-pending gate from run()
- drop StoredMintQuote type and local mintQuote copy
- drop sameMint and dead change_from_token branch
- inline seedFingerprint and drop redundant try wrappers
- drop orphaned const mq from renderQr
- hoist MELT_STATE_*_TTL constants to CashuGateway
- extract checkout dispatchers with fetch-mock test surface
- classify + project meltTrustedProofsToVendor into action list
- extract readRootData to helpers.ts with adapter shape
- OrderLock per-acquirer tokens + reconciler force flag

## v0.1.0

