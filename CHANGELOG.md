# Changelog

## [v0.3.2](https://github.com/cashubtc/cashu-for-woocommerce/compare/v0.3.1...v0.3.2) (2026-06-11)

### Bug Fixes

- bare version for SVN tag; chip icon for wp.org plugin page ([#12](https://github.com/cashubtc/cashu-for-woocommerce/pull/12))

## [v0.3.0](https://github.com/cashubtc/cashu-for-woocommerce/compare/v0.2.0...v0.3.0) (2026-06-09)

### Bug Fixes

- land Playground demo on Lightning-only tab ([f755468](https://github.com/cashubtc/cashu-for-woocommerce/commit/f75546861cb3d46041202fa878ef8428ab823f13))
- centre the Bitcoin glyph in checkout icon ([4308a47](https://github.com/cashubtc/cashu-for-woocommerce/commit/4308a47b5d83b115911f9f4878c9e5c4e5b829bb))

## [v0.2.0](https://github.com/cashubtc/cashu-for-woocommerce/compare/v0.1.1...v0.2.0) (2026-06-08)

### Bug Fixes

- handle PENDING melts gracefully, guard melt-quote rotation ([7d8767a](https://github.com/cashubtc/cashu-for-woocommerce/commit/7d8767a53fbefa71e5d6cb6b59551043c0e6d031))
- harden quote-rotation, race-safety and mint-routing on cashu orders ([079902d](https://github.com/cashubtc/cashu-for-woocommerce/commit/079902d64cdb28165184df64ce1ed48b5331db9b))
- address remaining MEDIUM/LOW code-review items (#11-23) ([b3d3bc8](https://github.com/cashubtc/cashu-for-woocommerce/commit/b3d3bc8ff651da7c7face50e36526ce576dc4129))
- retry mint-quote WS subscription before falling back to poll ([0cafcea](https://github.com/cashubtc/cashu-for-woocommerce/commit/0cafcea68f47378da4395ac929814fa85c7f09aa))
- second-pass review — mint routing, rate limits, lock contention, preimage verification ([70fd439](https://github.com/cashubtc/cashu-for-woocommerce/commit/70fd439b5c22a8147ecd03d2de01711763ea880e))
- guard preimageMatches against odd-length hex ([db513a1](https://github.com/cashubtc/cashu-for-woocommerce/commit/db513a11827f6bce190c5c68f6062029d5554394))
- recover stranded ISSUED proofs from localStorage ([dcc219f](https://github.com/cashubtc/cashu-for-woocommerce/commit/dcc219fa045c6005a0a5bf5239ac882d6e9f1493))
- skip melt if quote already PAID — avoid recovery UI on refresh-loop ([5cc86e3](https://github.com/cashubtc/cashu-for-woocommerce/commit/5cc86e359dcf0aa49cd50611bbb4f2e0760925b4))
- uppercase LIGHTNING QR + raw-invoice copy ([a091940](https://github.com/cashubtc/cashu-for-woocommerce/commit/a0919401e383c170636d126893c8b5b960ebbdb3))
- show 'Payment confirmed!' before redirect ([e1b6127](https://github.com/cashubtc/cashu-for-woocommerce/commit/e1b61270c9ca5b44edf6e398d5ad500ddcc39e9c))
- correct path-validation hook plan + drop cashu_enabled from getConfig ([cd4d3d0](https://github.com/cashubtc/cashu-for-woocommerce/commit/cd4d3d05314d330701b1104f71331a3c26f2c614))
- sanitize_default_path reads $_POST to see in-flight cashu_paths ([1ea1cb0](https://github.com/cashubtc/cashu-for-woocommerce/commit/1ea1cb09a9911b8e1b92057fed3487f69e55d135))
- localize admin UX-assist labels via wp_localize_script ([f31f34f](https://github.com/cashubtc/cashu-for-woocommerce/commit/f31f34f6a1858ffe4069da9711195c521f8cec5e))
- align array double-arrow in wp_localize_script call ([1880596](https://github.com/cashubtc/cashu-for-woocommerce/commit/18805960342d6a1a3bf2bfca7a009b0c217c4821))
- use desc (not label) for path checkbox text — WC checkbox renderer ignores label ([e60fa42](https://github.com/cashubtc/cashu-for-woocommerce/commit/e60fa42b12c182493ce6fcc461b5e72ad6e3655b))
- store cashu_paths as yes/no strings so checkbox renders correctly ([5b0bda1](https://github.com/cashubtc/cashu-for-woocommerce/commit/5b0bda1f96b2293e3f4115489b730a1403e4d0c3))
- silence WC Blocks + jQuery migrate console warnings ([f4f5d99](https://github.com/cashubtc/cashu-for-woocommerce/commit/f4f5d99b9338de7fb10bd92bd7e81a2cd22ee5d2))
- dump no-dev autoload before zipping the plugin ([cfda8b8](https://github.com/cashubtc/cashu-for-woocommerce/commit/cfda8b820e6f7f1964f8ad2598fd5d517e3d0d8b))
- drop vendor/ from zip, hand-roll PSR-4 autoload ([2de12a4](https://github.com/cashubtc/cashu-for-woocommerce/commit/2de12a41b8e04152d56fe2f6dd377aa265e04632))
- gate the success branch behind a single-flight flag ([9fd1dd0](https://github.com/cashubtc/cashu-for-woocommerce/commit/9fd1dd0945d245f98b19e0edc6bb38c54691ac99))
- preserve marker through transient mint outages ([eb6dbef](https://github.com/cashubtc/cashu-for-woocommerce/commit/eb6dbefe44de842bc40711b5ce3304ef3df06e7e))
- NUT-09 restore stranded mint-quote proofs on reload ([a3080c4](https://github.com/cashubtc/cashu-for-woocommerce/commit/a3080c480e6cff6726b47418d3599bb8f51ef601))
- recover orphaned change-proofs on melt-quote reload ([a1aebb9](https://github.com/cashubtc/cashu-for-woocommerce/commit/a1aebb92a4ea5a6b53ad92eccee024ad7ad1a647))
- re-probe melt quote state on meltProofsBolt11 failure ([59bca85](https://github.com/cashubtc/cashu-for-woocommerce/commit/59bca8506579622a9546f6fe02a6401de7f0ccb7))
- clarify recovery copy and normalise ellipsis ([c324134](https://github.com/cashubtc/cashu-for-woocommerce/commit/c324134896fb1820d3b1a8ea90df01be963dd4cc))
- advance wallet counters after NUT-09 restore ([52b0ff3](https://github.com/cashubtc/cashu-for-woocommerce/commit/52b0ff382d7fdcc66fb3b67be450614f86a9d6a5))
- assorted order-state and admin fixes ([ad08bbb](https://github.com/cashubtc/cashu-for-woocommerce/commit/ad08bbbef4839a21616143e32560cca977ac4210))
- render retry-melt button as link, not nested form ([b262c82](https://github.com/cashubtc/cashu-for-woocommerce/commit/b262c8277e41d39a2dd86c2efde6ee427afefee7))
- add required meta.author field ([32f1900](https://github.com/cashubtc/cashu-for-woocommerce/commit/32f1900d014999eeaefb61f7bf8c2604c8b9aedf))
- clear WC activation-redirect transient so landingPage actually lands ([c7b296c](https://github.com/cashubtc/cashu-for-woocommerce/commit/c7b296c2ff84c619940e482b21cf31ff1fa8508a))
- pre-enable Cashu gateway so settings work end-to-end ([beb331f](https://github.com/cashubtc/cashu-for-woocommerce/commit/beb331fc45c2cffe10dd7ba0b4399da597b4fd74))


### Features

- admin recovery meta-box, never rotate a paid mint quote ([28e866e](https://github.com/cashubtc/cashu-for-woocommerce/commit/28e866e7a9775fae53601da82fcc94315481a521))
- per-tab QR centre-icon overlay ([864f1a5](https://github.com/cashubtc/cashu-for-woocommerce/commit/864f1a544f2a4423705ad3b79af1e1ac5793f5e0))
- add CashuPaths helper for path bitmap + default resolution ([ee932b3](https://github.com/cashubtc/cashu-for-woocommerce/commit/ee932b3f012315ac50925ae57f2ee90c1aa8f392))
- one-time auto-flip gateway enable from legacy cashu_enabled ([8b653d1](https://github.com/cashubtc/cashu-for-woocommerce/commit/8b653d19ced1a3a05dd43546de775a371a600656))
- rework Cashu Settings tab — paths + default, drop enable ([9f05a82](https://github.com/cashubtc/cashu-for-woocommerce/commit/9f05a82adf151902658fe0d03a38d440ce11af5a))
- validate cashu_paths + cashu_default_path on save ([19db796](https://github.com/cashubtc/cashu-for-woocommerce/commit/19db7968081cab32230565d73daf1b63a7095fb7))
- render only enabled payment tabs + seed default ([f69425a](https://github.com/cashubtc/cashu-for-woocommerce/commit/f69425abec9ee7e154de49ba13d1329d984c15ee))
- seed currentMode from server-rendered default tab ([a525552](https://github.com/cashubtc/cashu-for-woocommerce/commit/a525552bf26f293ec4ce8855e475079ffd5fe5e8))
- client-side UX assist for path gating + default repopulation ([30a45e0](https://github.com/cashubtc/cashu-for-woocommerce/commit/30a45e083cfa806458b8bee2f3e0f6267e36120c))
- probe mint NUT-06 on save, require BOLT11/sat for both NUT-04 and NUT-05 ([c7acd3d](https://github.com/cashubtc/cashu-for-woocommerce/commit/c7acd3d75eeddc99e98230d5cc6796ffc6cdf95f))
- adopt official Cashu chip icon + rewrite gateway description ([5d8e9ac](https://github.com/cashubtc/cashu-for-woocommerce/commit/5d8e9ac17eeb12156eb28ad4c9b25488bea1a870))
- wipe global plugin state on plugin deletion ([3eb4105](https://github.com/cashubtc/cashu-for-woocommerce/commit/3eb4105d39690c7715f94fadc25f9725a9690cf6))
- show gateway icon in WC Blocks checkout ([857f1bc](https://github.com/cashubtc/cashu-for-woocommerce/commit/857f1bc034f863d4d078098796904d48b7c9694f))
- pre-stage pending marker before mint melt call ([086135f](https://github.com/cashubtc/cashu-for-woocommerce/commit/086135fa6cf21b6e257cbfdc98b134330b88f220))
- reconcile mint state on melt failure ([77fe272](https://github.com/cashubtc/cashu-for-woocommerce/commit/77fe27283a1e939ce00a16136a33ecfe0c75dad1))
- add MeltReconciler cron handler ([7267efa](https://github.com/cashubtc/cashu-for-woocommerce/commit/7267efaaae2e2c43ec1bb57c0b77f0ca8bcb93f6))
- schedule MeltReconciler cron hook on activation ([4cd385b](https://github.com/cashubtc/cashu-for-woocommerce/commit/4cd385bf39f04937d555269fb18b5cb9bd3c2f22))
- surface pending-melt reconciliation status ([20308a5](https://github.com/cashubtc/cashu-for-woocommerce/commit/20308a50d7cd304e6c7fbdf90d4baf76b9b9501f))
- add deterministic wallet seed derivation ([33cc946](https://github.com/cashubtc/cashu-for-woocommerce/commit/33cc946020bfaa6b637a72f2e115edf9f2549d48))
- seed cashu-ts wallet per-order for NUT-09 restore ([97a78f2](https://github.com/cashubtc/cashu-for-woocommerce/commit/97a78f2d52a33f73a2677862a73f986ad20f6053))
- add tryRestore NUT-09 recovery helper ([cec1e24](https://github.com/cashubtc/cashu-for-woocommerce/commit/cec1e24b546e25ee7bd88952d735d95a30f66ea7))
- add recovery-flow status strings ([0e4a328](https://github.com/cashubtc/cashu-for-woocommerce/commit/0e4a3286c3707471e8cf65218a1c36a0d7bf2a2f))
- surface previous-attempt-failed banner instead of silent reset ([ac6c722](https://github.com/cashubtc/cashu-for-woocommerce/commit/ac6c722c3aa1ea04b1207f49960c3b80a08d1512))
- close LN-leg in-flight gap via server marker write on PENDING ([8333500](https://github.com/cashubtc/cashu-for-woocommerce/commit/8333500c3c491f098f85481e1faa871cdc8f0cb4))
- refresh change-panel copy and add no-wallet onboarding ([1b8c26e](https://github.com/cashubtc/cashu-for-woocommerce/commit/1b8c26e37f0ca992e0fa768c16de8c2c62bd16b7))
- LNURL probe on lightning_address + retry button on stuck orders ([02f8965](https://github.com/cashubtc/cashu-for-woocommerce/commit/02f8965c364fcce057956782c25a582881504488))


### Performance

- raise mint state cache TTL and back off on rate-limited responses ([c1d6980](https://github.com/cashubtc/cashu-for-woocommerce/commit/c1d698049935943c9d368f45d9dabb50ade090f1))
- back off pollOrderStatus on consecutive PENDING responses ([e985b7e](https://github.com/cashubtc/cashu-for-woocommerce/commit/e985b7e2a418bd56e5a52ae79eee99d0c470a254))
- also cache mint state probe on receipt page render ([bc48c30](https://github.com/cashubtc/cashu-for-woocommerce/commit/bc48c30b1e1f60e2347ec9caab032e6fb0822e8c))


### Refactoring

- remove unused fee estimation ([887211c](https://github.com/cashubtc/cashu-for-woocommerce/commit/887211c3b19587750d00a0b63778bb340b85a6e6))
- BIP-321 QR + NUT-18 receiver, server-side mint quote, cashu-ts v4 ([e285716](https://github.com/cashubtc/cashu-for-woocommerce/commit/e285716460c5ef26c34e28d9e6684931af9447e0))
- lift mint-URL normaliser; fix internal admin-mint compares ([f2fc0f6](https://github.com/cashubtc/cashu-for-woocommerce/commit/f2fc0f682bf8e1e612def3f80309b600ff7363b2))
- uppercase BIP-321 URI + swap to qrcode-generator ([5b97347](https://github.com/cashubtc/cashu-for-woocommerce/commit/5b973477a54e7261bade52af64ac72cc1ea1751a))
- strict path-bitmap checks + idempotency test ([6500a18](https://github.com/cashubtc/cashu-for-woocommerce/commit/6500a18d6e78cf096db363747d49ad7890d2bead))
- drop redundant cashu_enabled check from is_available() ([ef49cb1](https://github.com/cashubtc/cashu-for-woocommerce/commit/ef49cb10aab9f20ad4b17251875567f4c938467c))
- rename seed domain string to cashu_wc_wallet_seed_v1 ([84c8e8d](https://github.com/cashubtc/cashu-for-woocommerce/commit/84c8e8df03fef77a2505ac82c8335b8f67f290ee))
- drop vestigial user-pending gate from run() ([413a555](https://github.com/cashubtc/cashu-for-woocommerce/commit/413a555edfa7ce13e54e383cb335a20ecbc42d57))
- drop StoredMintQuote type and local mintQuote copy ([db0ff42](https://github.com/cashubtc/cashu-for-woocommerce/commit/db0ff422731d28d172d422656d587a4d5970b1b9))
- drop sameMint and dead change_from_token branch ([058bd91](https://github.com/cashubtc/cashu-for-woocommerce/commit/058bd9181359e57f81bff5f43fbf45f0292198a9))
- inline seedFingerprint and drop redundant try wrappers ([8f39b41](https://github.com/cashubtc/cashu-for-woocommerce/commit/8f39b413d1d88d65abc69aeab6c71c16d13003b7))
- drop orphaned const mq from renderQr ([8fc8051](https://github.com/cashubtc/cashu-for-woocommerce/commit/8fc8051b9acbe9b729b88e8ad8be4f434cef8e3c))
- hoist MELT_STATE_*_TTL constants to CashuGateway ([fba3195](https://github.com/cashubtc/cashu-for-woocommerce/commit/fba319583b587ef36ed402a268852fd3fd46ac58))
- extract checkout dispatchers with fetch-mock test surface ([04b9fde](https://github.com/cashubtc/cashu-for-woocommerce/commit/04b9fde20ebf8c05f7fc118407341cdeaa730a65))
- classify + project meltTrustedProofsToVendor into action list ([cf40566](https://github.com/cashubtc/cashu-for-woocommerce/commit/cf40566f34da9d3adda062a035087742041829f0))
- extract readRootData to helpers.ts with adapter shape ([f97bf0d](https://github.com/cashubtc/cashu-for-woocommerce/commit/f97bf0d57ba581815f1fdc95ea66b4776c2de6f9))
- OrderLock per-acquirer tokens + reconciler force flag ([e992687](https://github.com/cashubtc/cashu-for-woocommerce/commit/e9926875a5f799746b2b93c4d3f86ce555771d75))

## [v0.1.0](https://github.com/cashubtc/cashu-for-woocommerce/releases/tag/v0.1.0) (2026-01-16)

