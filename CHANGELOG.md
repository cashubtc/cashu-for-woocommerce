# Changelog

## Unreleased

### <!-- 0 -->🚀 Features

- admin recovery meta-box, never rotate a paid mint quote (Rob Woodgate)

- per-tab QR centre-icon overlay (Rob Woodgate)

- add CashuPaths helper for path bitmap + default resolution (Rob Woodgate)

- one-time auto-flip gateway enable from legacy cashu_enabled (Rob Woodgate)

- rework Cashu Settings tab — paths + default, drop enable (Rob Woodgate)

- validate cashu_paths + cashu_default_path on save (Rob Woodgate)

- render only enabled payment tabs + seed default (Rob Woodgate)

- seed currentMode from server-rendered default tab (Rob Woodgate)

- client-side UX assist for path gating + default repopulation (Rob Woodgate)

- probe mint NUT-06 on save, require BOLT11/sat for both NUT-04 and NUT-05 (Rob Woodgate)

- adopt official Cashu chip icon + rewrite gateway description (Rob Woodgate)

- wipe global plugin state on plugin deletion (Rob Woodgate)

- show gateway icon in WC Blocks checkout (Rob Woodgate)

- pre-stage pending marker before mint melt call (Rob Woodgate)

- reconcile mint state on melt failure (Rob Woodgate)

- add MeltReconciler cron handler (Rob Woodgate)

- schedule MeltReconciler cron hook on activation (Rob Woodgate)

- surface pending-melt reconciliation status (Rob Woodgate)


### <!-- 1 -->🐛 Bug Fixes

- handle PENDING melts gracefully, guard melt-quote rotation (Rob Woodgate)

- harden quote-rotation, race-safety and mint-routing on cashu orders (Rob Woodgate)

- address remaining MEDIUM/LOW code-review items (#11-23) (Rob Woodgate)

- retry mint-quote WS subscription before falling back to poll (Rob Woodgate)

- second-pass review — mint routing, rate limits, lock contention, preimage verification (Rob Woodgate)

- guard preimageMatches against odd-length hex (Rob Woodgate)

- recover stranded ISSUED proofs from localStorage (Rob Woodgate)

- skip melt if quote already PAID — avoid recovery UI on refresh-loop (Rob Woodgate)

- uppercase LIGHTNING QR + raw-invoice copy (Rob Woodgate)

- show 'Payment confirmed!' before redirect (Rob Woodgate)

- correct path-validation hook plan + drop cashu_enabled from getConfig (Rob Woodgate)

- sanitize_default_path reads $_POST to see in-flight cashu_paths (Rob Woodgate)

- localize admin UX-assist labels via wp_localize_script (Rob Woodgate)

- align array double-arrow in wp_localize_script call (Rob Woodgate)

- use desc (not label) for path checkbox text — WC checkbox renderer ignores label (Rob Woodgate)

- store cashu_paths as yes/no strings so checkbox renders correctly (Rob Woodgate)

- silence WC Blocks + jQuery migrate console warnings (Rob Woodgate)

- dump no-dev autoload before zipping the plugin (Rob Woodgate)

- drop vendor/ from zip, hand-roll PSR-4 autoload (Rob Woodgate)

- gate the success branch behind a single-flight flag (Rob Woodgate)


### <!-- 10 -->💼 Other

- sync FR translation with settings consolidation strings (Rob Woodgate)

- stop bumping POT-Creation-Date on every makepot run (Rob Woodgate)


### <!-- 2 -->🚜 Refactor

- remove unused fee estimation (Rob Woodgate)

- BIP-321 QR + NUT-18 receiver, server-side mint quote, cashu-ts v4 (Rob Woodgate)

- lift mint-URL normaliser; fix internal admin-mint compares (Rob Woodgate)

- uppercase BIP-321 URI + swap to qrcode-generator (Rob Woodgate)

- strict path-bitmap checks + idempotency test (Rob Woodgate)

- drop redundant cashu_enabled check from is_available() (Rob Woodgate)


### <!-- 3 -->📚 Documentation

- expand agent release guidance (Rob Woodgate)

- add agent dev essentials (Rob Woodgate)

- fix stubOptionsStore docblock — takes by ref, doesn't return (Rob Woodgate)

- note ts→js rebuild step for wp-env iteration (Rob Woodgate)

- point Plugin URI at the repo and add Source section to readme (Rob Woodgate)


### <!-- 5 -->🎨 Styling

- split <?php tag from foreach for phpcs (Rob Woodgate)

- apply prettier formatting from build pipeline (Rob Woodgate)


### <!-- 6 -->🧪 Testing

- add Brain\Monkey + Mockery integration test rails (Rob Woodgate)

- pin request_melt_bolt11 body shape and error branches (Rob Woodgate)

- cover Bolt11::preimageMatches happy + sad paths (Rob Woodgate)

- cover claim_melt_quote — preimage and mint-state branches (Rob Woodgate)

- cover resolve_pending_melt TTL aging + branches (Rob Woodgate)

- surface PENDING on lock contention (H5 fix) (Rob Woodgate)

- spot-expired + mint-PAID regression (Rob Woodgate)

- tabs + strip dead constructor stubs from IsAvailableTest (Rob Woodgate)

- use all-on input to isolate normalisation from coercion (Rob Woodgate)

- smoke test receipt_page tab rendering (Rob Woodgate)


### <!-- 7 -->⚙️ Miscellaneous Tasks

- add changelog tooling (Rob Woodgate)

- add cloudflared tunnel scripts for local cashu testing (Rob Woodgate)

- apply dev mtime cache-buster to thanks.js and public.css (Rob Woodgate)

- add debug logs at new state transitions (Logger audit) (Rob Woodgate)

- regenerate POT, update fr_FR with new strings (Rob Woodgate)

- regenerate POT — payment_confirmed string + line drift (Rob Woodgate)

- log wallet-sent mint URL on cashu_bad_mint (Rob Woodgate)

- compact Coinbase spot log to single-line JSON (Rob Woodgate)

- update gitignore (Rob Woodgate)

- compact Coinbase spot log to single-line JSON (Rob Woodgate)

- regenerate POT for settings consolidation + paths (Rob Woodgate)

- gitignore .playwright-mcp (Rob Woodgate)

- add wp.org publish workflow, pin action SHAs, add dependabot (Rob Woodgate)

- prepare 0.2.0 (Rob Woodgate)

- add plugin directory assets (banner + icons) (Rob Woodgate)

- address Plugin Check findings ahead of submission (Rob Woodgate)

- delay the "buy the developer a coffee" nag by 30 days (Rob Woodgate)

- regenerate .pot line numbers after autoloader move (Rob Woodgate)

- trim message (Rob Woodgate)

- strip leading whitespace before <?php in bootstrap (Rob Woodgate)

- reshape chip logo to rounded square to match Woo (Rob Woodgate)

- format (Rob Woodgate)

- sync readme (Rob Woodgate)

- update docs (Rob Woodgate)

- replace Grunt with pure-PHP build scripts (Rob Woodgate)

- bump the github-actions group with 4 updates (dependabot[bot])

- add PR build check that runs build.sh and tests (Rob Woodgate)

- switch minify to 'oxc' for vite 8 (Rob Woodgate)

- extend pending-marker TTL to 24h (Rob Woodgate)


## v0.1.0

### <!-- 10 -->💼 Other

- checkout script (Rob Woodgate)

- checkout (Rob Woodgate)



