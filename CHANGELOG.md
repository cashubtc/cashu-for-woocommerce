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


### Build

- stop bumping POT-Creation-Date on every makepot run
- wire tsc --noEmit into lint:check pipeline
- fix npm run check to invoke composer test
- pin useUnknownInCatchVariables explicitly in tsconfig
- align prettier scope so npm run check is comprehensive
- extract wp-env seed-store script, dismiss WC wizard, expand blueprint products


### CI

- add wp.org publish workflow, pin action SHAs, add dependabot
- bump the github-actions group with 4 updates
- add PR build check that runs build.sh and tests


### Chores

- add changelog tooling
- add cloudflared tunnel scripts for local cashu testing
- apply dev mtime cache-buster to thanks.js and public.css
- add debug logs at new state transitions (Logger audit)
- regenerate POT, update fr_FR with new strings
- regenerate POT — payment_confirmed string + line drift
- log wallet-sent mint URL on cashu_bad_mint
- compact Coinbase spot log to single-line JSON
- update gitignore
- compact Coinbase spot log to single-line JSON
- regenerate POT for settings consolidation + paths
- gitignore .playwright-mcp
- prepare 0.2.0
- add plugin directory assets (banner + icons)
- address Plugin Check findings ahead of submission
- delay the "buy the developer a coffee" nag by 30 days
- regenerate .pot line numbers after autoloader move
- trim message
- strip leading whitespace before <?php in bootstrap
- reshape chip logo to rounded square to match Woo
- format
- sync readme
- update docs
- replace Grunt with pure-PHP build scripts
- bump the npm group across 1 directory with 7 updates
- switch minify to 'oxc' for vite 8
- extend pending-marker TTL to 24h
- build/format
- drop unused tests environment
- actually drop the tests env config


### Documentation

- expand agent release guidance
- add agent dev essentials
- fix stubOptionsStore docblock — takes by ref, doesn't return
- note ts→js rebuild step for wp-env iteration
- sync FR translation with settings consolidation strings
- point Plugin URI at the repo and add Source section to readme
- regenerate changelog for proof-loss reconciliation
- regenerate changelog for marker-preservation fix
- sync class docblock with new recovery copy
- surface npm run check as the pre-push verification command
- trim probe docblock to mechanism only
- add WordPress Playground blueprint + one-click link
- pull from GH release, dismiss WC wizard, seed product
- import WC sample products XML, drop inline product seeding
- decouple GitHub README from wp.org readme.txt


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
- split <?php tag from foreach for phpcs
- apply prettier formatting from build pipeline
- rename seed domain string to cashu_wc_wallet_seed_v1
- prettier wrap console.warn args
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


### Tests

- add Brain\Monkey + Mockery integration test rails
- pin request_melt_bolt11 body shape and error branches
- cover Bolt11::preimageMatches happy + sad paths
- cover claim_melt_quote — preimage and mint-state branches
- cover resolve_pending_melt TTL aging + branches
- surface PENDING on lock contention (H5 fix)
- spot-expired + mint-PAID regression
- tabs + strip dead constructor stubs from IsAvailableTest
- use all-on input to isolate normalisation from coercion
- smoke test receipt_page tab rendering
- add vitest harness and Tier 1 unit tests for checkout helpers
- extract wallet/runner from checkout and add Tier 2 unit tests
- extract checkOrderStatus decision logic + fix priority bug
- extract small decision helpers + add coverage
- extract rememberChangeItem with dedup+trim coverage
- pin _cashu_last_payment_attempt_at lifecycle across controllers
- extract composeRestUrl with slash-normalization coverage
- regression coverage for setup-paid race, LN-leg pre-stage marker, reconciler lock contention

## v0.1.0

