# Agent Notes

## Release workflow (vX.Y.Z)

- Update versions in `package.json`, `cashu-for-woocommerce.php` (header + `CASHU_WC_VERSION`), `tests/bootstrap.php`, and stable tag/changelog in `readme.txt`.
- Run `./build.sh` to generate distributable assets.
- Regenerate `CHANGELOG.md` with the new tag: `npm run changelog -- --tag vX.Y.Z`. The `--tag` flag promotes the `## Unreleased` section to `## [vX.Y.Z](compare-url) (date)`.
- `CHANGELOG.md` is fully generated — never hand-edit it; regeneration rebuilds the whole file from commit history and discards manual edits. A non-conventional squash subject simply won't appear (which is why PR titles must be conventional), and a release whose commits were all unconventional is omitted entirely.
- `main` is protected: land the changes via a release PR titled `chore(release): X.Y.Z`.
- Create an annotated tag `vX.Y.Z` and push tags.
- Publish a GitHub release for the tag (non‑prerelease) — for the body, paste the relevant section of `CHANGELOG.md` (GitHub's "Generate release notes" button only walks PR merges and produces nothing useful for direct-to-main history). Publishing fires `.github/workflows/release-zip.yml`, which builds and uploads `cashu-for-woocommerce.zip`.
- The same release event also triggers `.github/workflows/wporg-deploy.yml`, which pushes the same build to the WordPress.org plugin directory (see "Publishing to WordPress.org" below for one-time setup).

## Publishing to WordPress.org

The `wporg-deploy.yml` workflow uses [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy) to push to the wp.org SVN repo. It reuses `build.sh` end-to-end, so wp.org gets bit-for-bit the same files as the GitHub release zip.

**Per-release flow (the automated path):**

Publishing a non-prerelease GitHub release fires both `release-zip.yml` (uploads the zip to the release page) and `wporg-deploy.yml` (pushes to wp.org SVN). No extra action needed once the secrets are in place.

**Re-running a deploy manually:**

If a deploy fails partway, or you need to re-push a tag without cutting a new GitHub release, run `wporg-deploy.yml` manually with the same `vX.Y.Z` tag and `dry_run = false`.

**Gotchas to watch for:**

- The workflow refuses to deploy unless the tag, `package.json` version, plugin header version, **and** `Stable tag:` in `readme.txt` all agree. Forgetting to bump `Stable tag` is the classic wp.org footgun — the install page would still show the old version even after /trunk/ updates.
- wp.org plugin page graphics (banner, icon, screenshots) live in the `.wordpress-org/` directory at repo root, following [10up's asset spec](https://github.com/10up/action-wordpress-plugin-deploy#wordpressorg-assets). The deploy syncs it to SVN `/assets/` destructively — it must always hold the complete set, and asset changes only reach wp.org when a release tag containing them is deployed.

## Project specifics

- The release workflow validates tag version matches `package.json` and the plugin header version in `cashu-for-woocommerce.php`.
- `README.md` is the hand-curated GitHub front page; `readme.txt` is the separate wp.org artifact. They are NOT mirrored — edit each in its own register.
- Translations are managed via translate.wordpress.org once published — the plugin ships no bundled POT/PO/MO. All user-facing strings use the `cashu-for-woocommerce` text domain via standard WP i18n functions.
- For full dev setup details, see `CONTRIBUTING.md`.

## Dev essentials

- Tooling: Node/npm, PHP/Composer; Docker required for `wp-env`.
- Common scripts: `npm run build`, `npm run format`, `npm run lint`.
- **Pre-push verification:** `npm run check` runs the full pipeline in ~5s, no wp-env required — prettier `--check`, `phpcs`, stylelint, `tsc --noEmit`, vitest (TS unit tests, jsdom), and phpunit (PHP integration tests). Use it before every push.
- **Live smoke tests (manual, not in `check`/CI):** `tests/e2e/` has Playwright specs that drive a real store to a real settlement and block on a human paying the printed invoice — `live-checkout` (both legs settle) and `live-recovery` (strand proofs mid-melt, force NUT-09 restore). Run against the tunnel: `CASHU_E2E_BASE_URL=https://<tunnel> npx playwright test --headed` (one-time `npx playwright install chromium`). Full how-to + gotchas in CONTRIBUTING.md → "Live end-to-end smoke tests".
- Local WP env: `npm run wp-env:start`, `npm run wp-env:seed-store`, `npm run wp-env:stop`.
- Logs: plugin PHP log is `debug.log`; WooCommerce logs in admin.
- **TS rebuild after edits:** `src/ts/*.ts` is built by vite into `assets/js/cashu/` (gitignored). After editing TS, run `npx vite build` so wp-env / the browser pick up the change — hard-refresh the page to bust the asset cache. PHP and admin JS (`assets/js/backend/*.js`) need no build step.

## Commit style

- Use Conventional Commits (eg: `feat: ...`, `fix: ...`, `chore: ...`, `docs: ...`, `refactor: ...`, `test: ...`, `ci: ...`, `build: ...`).
- Keep subjects concise and lowercase; include scope only when helpful (eg: `feat(checkout): ...`).
- Extended commit messages are good, but keep them terse: a sparse "what changed" summary with minimal framing is ideal. 3-4 sentences max overall.
- **PR titles MUST be Conventional Commits too.** PRs are squash-merged, so the PR title becomes the single commit subject on `main` — and `npm run changelog` (git-cliff) groups the changelog by that prefix. A non-conventional PR title (eg "Settlement hardening: …") lands as an uncategorised commit that git-cliff silently drops, so the release changelog has to be hand-written. Title the PR `fix: …` / `feat: …` etc. and the changelog generates itself.
- **Check the title again immediately before `gh pr merge --squash`** — the squash subject is permanent on protected `main`; it cannot be retitled after merge.

## Changelog vs release notes

`CHANGELOG.md` is the canonical curated changelog (features, fixes, performance, refactoring; chores/tests/build/docs filtered). Regenerated at release time with `npm run changelog -- --tag vX.Y.Z`. Lives in the repo so anyone browsing GitHub sees it.

Entry links: PR-merged commits (subject ending `(#N)`) render the PR link only; pre-PR direct-to-main entries keep their commit-hash link, since that's their only reference — so regeneration leaves the historical sections' format unchanged.

`npm run changelog` (no extra args) regenerates `CHANGELOG.md` with the unreleased section showing what's queued for the next release — handy for previewing before tagging.

**GitHub release body:** paste the relevant section of `CHANGELOG.md`. GitHub's "Generate release notes" button only walks PR merges, which is empty for direct-to-main history. Extract a single version section with:

```bash
awk '/^## \[vX\.Y\.Z\]/{p=1; print; next} /^## \[/{if (p) exit} p' CHANGELOG.md
```

…or skip the awk dance and use `gh release create vX.Y.Z --notes-file <(awk ... CHANGELOG.md)`. Once PR-based contributions become the norm, GitHub's auto-generator becomes the easier path.
