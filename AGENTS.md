# mmi-teach — notes for AI agents

- Site-only plugin for the MannMade teaching site. Not part of the distributed MMI suite:
  no mmi-suite-common, MMI_Settings, MMI_Logger or Throttler here. Http.php is the throttle (2 s per host).
- Zero cost: every source is a public listing. Never add a paid API, a scraping service or an account-based board.
- A source's `fetch()` throws instead of returning a partial list — a short list marks postings removed.
- Page views read synced posts, post meta and data files only. Anything slow belongs in `Refresher` or `Jobs::sync()` (cron/CLI).
- County matching lives in `Places`; add checked employer answers to `data/employers.json`, then `wp mmi-teach reclassify`.
- Every heading in an admin table sorts; ID is the first column (owner rules).
- Bump `Version` + `MMI_TEACH_VERSION` and add a `changelog.md` entry for every change (one bump per session).
- Agencies: posting text beats curated routes; never mark a sub job's agency without a source. Routes in data/agencies.json are for daily substitute teachers unless `by_kind` says otherwise; aides get an agency only from the posting's own words.
- Requirements: label every part with its basis (posted, text, rule, inferred); never present an inferred license as the posting's own requirement. New WECAN license names must be mapped in data/licenses.json (`wecan` lists).
- `data/rules.json`: every entry needs a public https source, `verified_on` and `confidence`; disputed facts get `conflict`. Never put anything from a person's own emails or applications in this public repo.
- Never collect SSNs, ID/I-9 documents, bank, tax, medical or TB results, criminal history, or files. Forms use fixed options, not free text, wherever such data could be typed.
- The site is Bricks: style with Bricks global classes, palette and theme style (`Build\Site`), never a plugin stylesheet; repeated markup is a query loop; text sizes in em. Run `wp --user=1 mmi-teach build-site` after changing `Build\Site` (`--step=settings` first in its own process when settings change). Pages edited in the builder are skipped unless `--force`d.
- Applications never go in a Bricks post loop; per-visitor lists are `Bricks\Queries` types scoped in PHP. Validate any ID that arrives from a form.
- DPI license lookup (`Dpi_Lookup`): DPI's public ELO pages only, one visitor's search at a time, rate-limited per visitor and site-wide. Never store or log the name searched; matches and the picked record live in the visitor's cookies only. A picked file number must come from the visitor's own match list. Parser tests run on saved pages in `tests/fixtures/dpi/` (fictional names).
- Tests: `wp eval-file tests/eligibility.php` and `node tests/e2e/flow.js chromium 1440` / `webkit 390` before shipping.
