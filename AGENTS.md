# AGENTS.md

This file provides guidance to coding agents working with code in this repository.

## What this is

A single-file WordPress plugin (`hide-trackbacks.php`) published on WordPress.org as [Hide Trackbacks](https://wordpress.org/plugins/hide-trackbacks/). It hides trackbacks/pingbacks from the front-end comment list while keeping them in the admin. There is no build step, no dependency manager, and no test suite.

## Commands

There is no test suite. CI (`ci.yml`) runs three checks, all reproducible locally:

```bash
# 1. Syntax lint (CI runs this on PHP 7.4, 8.1, 8.2, 8.3, 8.4)
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. Removed/deprecated PHP APIs — what php -l cannot see.
#    testVersion must stay in step with "Requires PHP" in readme.txt.
composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require dealerdirect/phpcodesniffer-composer-installer phpcompatibility/phpcompatibility-wp
"$(composer global config bin-dir --absolute | tail -1)"/phpcs -p . --extensions=php \
  --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-

# 3. The official WordPress.org Plugin Check (headers, readme.txt, deprecated WP APIs)
#    runs via wordpress/plugin-check-action; easiest to read from the CI log.
#    CI first builds the package .distignore describes and checks *that*, so the
#    check sees what users download. Reproduce the package locally with:
mkdir -p build/hide-trackbacks && git archive HEAD | tar -x -C build/hide-trackbacks
while IFS= read -r p || [ -n "$p" ]; do case "$p" in ''|\#*) continue ;; esac; \
  rm -rf "build/hide-trackbacks/${p#/}"; done < .distignore
```

`tested-up-to.yml` runs weekly, bumping `Tested up to:` in `readme.txt` from api.wordpress.org. It opens a pull request with auto-merge on, so the bump merges once CI passes; the merge fires `on-master-asset-update.yml`, which syncs the readme to WordPress.org. Plugins untested against recent major releases get de-emphasised in the directory.

Two constraints explain why it looks the way it does, and both bite any future automation here:

- **`GITHUB_TOKEN` activity does not start new workflow runs.** A branch it pushes never gets the required `test` check, and a merge it makes never fires `on-master-asset-update.yml`. The workflow therefore pushes the branch and enables auto-merge with `RELEASE_TOKEN` (see the release section below), so CI runs on the bump and the merge syncs the readme to the trunk.
- **Merge method.** It uses `--merge`, matching `dependabot-auto-merge.yml`, so it relies on a method already known to be enabled on the repository.

## Architecture

`SVD_HideTrackbacks` is instantiated on `plugins_loaded` (stored in the `$svd_hide_trackbacks` global) and registers three filters that must stay consistent with each other:

- `comments_array` → `strip_comments()` removes trackbacks/pingbacks from the rendered list.
- `get_comments_number` → `comment_count()` recomputes the count for the current post inside the loop.
- `the_posts` → `update_post_comment_count()` rewrites `WP_Post::$comment_count` for each post in a query, so archive listings match.

All three funnel into `_count_comments()` / `_strip_trackbacks()`, which re-query via `get_approved_comments()` and filter on `comment_type`. Any change to what counts as "strippable" belongs in `is_strippable_comment()` only — the rest derives from it.

Note: `strip_comments()` also assigns to the `global $comments`. The inline `//TODO test why global is used.` marks this as unexplained legacy behaviour, so leave it in place unless deliberately investigating it.

## Verifying by hand

There is no test suite, and CI checks syntax and API compatibility only — never behaviour. Manual verification needs a WordPress install with a post that carries real pingbacks and trackbacks. Be aware that the plugin's author was never able to get trackbacks to display even with the plugin disabled, so **"no trackbacks visible" is not by itself evidence that the plugin works** — confirm the post genuinely has some first, via the admin comment list.

## Release process

Versions are published to WordPress.org by CI, not manually:

- Push to `master` → `on-master-asset-update.yml` syncs `readme.txt` and `.wordpress-org/` assets to SVN trunk. It does **not** sync plugin code.
- Push a git tag → `on-tag-deploy.yml` deploys that tag as a new plugin release. **A new version only ships when a tag is created.**

Because only a tag deploys code, `master` normally carries plugin code the trunk has not received. The asset-update action treats that as a reason to abort (`🛑 Other files have been modified; changes not deployed`), so `on-master-asset-update.yml`, the workflow that calls it, sets `IGNORE_OTHER_FILES: true`. Without it, readme updates stay blocked for the entire gap between merging a code change and tagging a release.

So a release change means updating, in lockstep: the `Version:` header in `hide-trackbacks.php`, `Stable tag:` in `readme.txt`, a new `== Changelog ==` entry, and then a matching git tag.

Two rules keep that safe, and they pull in opposite directions:

- **Do not raise `Stable tag:` before the matching tag is pushed.** Merging to `master` syncs `readme.txt` to trunk immediately, and WordPress.org reads `Stable tag` from trunk — so a `Stable tag` naming a tag absent from SVN breaks downloads for every user.
- **Do not raise `Version:` on its own either.** Plugin Check enforces `Version` == `Stable tag` and fails the build on a mismatch.

⚠️ **`master` is a protected branch requiring a status check named `test`, which `GITHUB_TOKEN` cannot satisfy.** Left alone, the `Release` workflow's deploy step succeeds while its commit-and-tag step is rejected, leaving WordPress.org released and git behind — dangerous, because `master`'s `Stable tag` then gets synced to the trunk on the next push and can roll users back to the previous version.

`release.yml` gets past this with a `RELEASE_TOKEN` secret: a fine-grained PAT (`Contents` and `Pull requests`: read and write; `tested-up-to.yml` uses it to open and auto-merge pull requests) whose owner is a repository admin. Branch protection here has `enforce_admins` disabled, so an admin's push is exempt from the `test` check. A ruleset with the `github-actions` app as a bypass actor is *not* an option — GitHub only accepts app bypass actors on organisation-owned repositories, and this repository belongs to a user. The workflow checks the secret exists before deploying, so a missing token cannot leave a release published but unrecorded.

Note which token pushes what: the release commit goes up with the PAT, the tag with `GITHUB_TOKEN`. That is deliberate — a PAT-pushed tag *does* start workflow runs, so it would fire `on-tag-deploy.yml` into a second deploy of a version already in SVN. `ci.yml` provides `test` as an aggregate over the real jobs so the requirement is satisfiable for pull requests that a person or a PAT opens. A pull request that `GITHUB_TOKEN` opens never gets the check.

**In practice, do not do any of this by hand — run the `Release` workflow** (`release.yml`, Actions tab → Release → Run workflow) with the version number. It encodes every rule below: it refuses a version with no changelog entry or an existing tag, bumps both lines together, deploys, then commits and tags. It takes a `dry_run` option that rehearses without publishing or pushing. It deploys *before* touching git, so a failure leaves nothing half-done and the run can just be repeated. Note it deploys inline rather than relying on `on-tag-deploy.yml`, because a tag pushed with `GITHUB_TOKEN` does not start workflow runs.

The underlying rule, which the workflow implements: the version bump belongs to the release commit and nowhere else — raise both lines together, then push the tag. Ordinary fixes land on `master` at the *current* version, which costs nothing — only a tag deploys plugin code, so `master` running ahead of the released version is invisible to users. `readme.txt` may carry a `== Changelog ==` entry for the next version before that version exists; nothing checks changelog headings against `Version`.

`readme.txt` is the WordPress.org plugin page (its own markup dialect, not Markdown) and also declares compatibility: `Requires PHP`, `Requires at least`, `Tested up to`. `.distignore` controls what is excluded from the deployed package.
