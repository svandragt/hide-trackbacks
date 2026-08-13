# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-file WordPress plugin (`hide-trackbacks.php`) published on WordPress.org as [Hide Trackbacks](https://wordpress.org/plugins/hide-trackbacks/). It hides trackbacks/pingbacks from the front-end comment list while keeping them in the admin. There is no build step, no dependency manager, and no test suite.

## Commands

```bash
# The only check CI runs: PHP syntax lint over every file
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

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

`readme.txt` is the WordPress.org plugin page (its own markup dialect, not Markdown) and also declares compatibility: `Requires PHP`, `Requires at least`, `Tested up to`. `.distignore` controls what is excluded from the deployed package.
