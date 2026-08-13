# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
```

`tested-up-to.yml` runs monthly, bumping `Tested up to:` in `readme.txt` from api.wordpress.org. It opens a pull request, merges it, and syncs to WordPress.org in a single unattended run. Plugins untested against recent major releases get de-emphasised in the directory.

Two constraints explain why it looks the way it does, and both bite any future automation here:

- **`GITHUB_TOKEN` activity does not start new workflow runs.** No checks report on a pull request the workflow opened, so `gh pr merge --auto` would queue behind required checks that never arrive; and the merge does not fire `on-master-asset-update.yml`, so the workflow pushes to the trunk itself. Without that, a bump lands in git and never reaches the plugin directory.
- **Merge method.** It uses `--merge`, matching `dependabot-auto-merge.yml`, so it relies on a method already known to be enabled on the repository.

## Architecture

`SVD_HideTrackbacks` is instantiated on `plugins_loaded` (stored in the `$svd_hide_trackbacks` global) and registers three filters that must stay consistent with each other:

- `comments_array` → `strip_comments()` removes trackbacks/pingbacks from the rendered list.
- `get_comments_number` → `comment_count()` recomputes the count for the current post inside the loop.
- `the_posts` → `update_post_comment_count()` rewrites `WP_Post::$comment_count` for each post in a query, so archive listings match.

All three funnel into `_count_comments()` / `_strip_trackbacks()`, which re-query via `get_approved_comments()` and filter on `comment_type`. Any change to what counts as "strippable" belongs in `is_strippable_comment()` only — the rest derives from it.

Note: `strip_comments()` also assigns to the `global $comments`; `devnotes.md` flags this as unexplained legacy behaviour, so leave it in place unless deliberately investigating it.

## Release process

Versions are published to WordPress.org by CI, not manually:

- Push to `master` → `on-master-asset-update.yml` syncs `readme.txt` and `.wordpress-org/` assets to SVN trunk.
- Push a git tag → `on-tag-deploy.yml` deploys that tag as a new plugin release. **A new version only ships when a tag is created.**

So a release change means updating, in lockstep: the `Version:` header in `hide-trackbacks.php`, `Stable tag:` in `readme.txt`, a new `== Changelog ==` entry, and then a matching git tag.

**Do not raise `Stable tag:` until the matching tag is actually pushed.** Merging to `master` syncs `readme.txt` to trunk immediately, and WordPress.org reads `Stable tag` from trunk — so a `Stable tag` pointing at a tag that does not exist in SVN yet breaks downloads for every user. Unreleased work therefore sits on `master` with `Version:` ahead of `Stable tag:`, which is the expected state, not drift to "fix".

`readme.txt` is the WordPress.org plugin page (its own markup dialect, not Markdown) and also declares compatibility: `Requires PHP`, `Requires at least`, `Tested up to`. `.distignore` controls what is excluded from the deployed package.
