# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

MediaWiki extension (`type: parserhook`, requires MediaWiki >= 1.35) that exposes OpenSearch
view-equivalent queries, `query_string` full-text search, and single-document fetches inside wiki
pages and via the MediaWiki API. It was renamed from `CouchDB_Query` when its backing store moved
from CouchDB to OpenSearch; the legacy `#CouchDB_Query_table`/`#CouchDB_Query_field` magic words,
`couchdb-*` API actions, and `$wgCouchDB_Query` global all still work as aliases - see
[docs/migration-from-couchdb.md](docs/migration-from-couchdb.md) for the full mapping and
deprecation timeline. No build, lint, or test tooling is wired up; PHP runs inside MediaWiki, JS is
shipped as-is and LESS is compiled by ResourceLoader via `ResourceModules`. `composer.json` exists
but only declares metadata plus `php >= 7.4` and `composer/installers` (it is for
`mediawiki-extension` installs; there is no autoload block and no `require-dev`, so
`composer install` gives you nothing to run). The only static-analysis config is `.jshintrc` for
`libs/main.js`.

## Architecture

The extension is registered through `extension.json` (manifest_version 2). `OpenSearch_Query.php`
(root) is a stub that errors out if someone tries the legacy `require_once` style - always load
with `wfLoadExtension( 'OpenSearch_Query' )`. `CouchDB_Query.php` is kept as the same kind of stub
under the old filename, so a stale `require_once( '.../CouchDB_Query.php' )` from an old
`LocalSettings.php` still dies with a clear message instead of a fatal "class not found".

Request flow has two entry points that share the same backend helpers:

1. Parser functions (rendered in wiki pages)
   - `OpenSearch_QueryHooks::onParserFirstCallInit` (`includes/OpenSearch_QueryHooks.php`)
     registers two `SFH_OBJECT_ARGS` parser functions defined in `includes/OpenSearch_Query.php`:
     `#OpenSearch_Query_table` and `#OpenSearch_Query_field`. `OpenSearch_Query.i18n.magic.php`
     lists `CouchDB_Query_table` / `CouchDB_Query_field` as aliases of the same two hooks.
   - These functions only emit placeholder markup plus `addModules( 'ext.OpenSearch_Query' )`. They
     do NOT call OpenSearch. Markup is built with `Html::element( 'div', $attrs )`, not string
     concatenation, so `data-*` attribute values are always escaped - the original CouchDB_Query had
     an HTML-injection bug here (unescaped concatenation into single-quoted attributes) that this
     fixes.
   - Every emitted div carries both CSS classes, e.g.
     `class="opensearch-query-table couchdb-query-table"`, so old and new JS selectors and any
     site CSS keyed on the old class keep matching.
   - Accepted `#OpenSearch_Query_table` attributes (whitelist in `$attrs_ref`): `limit`, `full`,
     `header`, `fields`, `type`, `index`, `query`, `class`, `start`, `end`, `db`, `text`, `extra`,
     `prefix`, `prefixurl`, `prefixcondurl`. `db` defaults to `$wgDBname`. There is no `default`
     attribute (the old code initialised one but never whitelisted or used it; it was dropped
     rather than wired up). `raw` (comma-separated field names to render unescaped, see the JS
     section) is read directly by `libs/main.js` from a `data-raw` attribute, but is **not** yet in
     the parser function's whitelist - add it there if you want wikitext to set it.
   - `type` is not a symbolic kind: `libs/main.js` normalises it to one of two canonical API action
     names (`opensearch-query`, `opensearch-search`) before ever calling the API, mapping the
     legacy `couchdb-query` → `opensearch-query` and `couchdb-lucene-query` /
     `couchdb-nouveau-query` → `opensearch-search`. Writing `type=opensearch-query` or
     `type=opensearch-search` directly also works.
   - `libs/main.js` (loaded as the `ext.OpenSearch_Query` ResourceModule, with `styles/main.less`
     and `jquery.tablesorter` as dep) scans `.opensearch-query-table, .couchdb-query-table` divs on
     `$(document).ready`, reads the `data-*` attributes, calls the MediaWiki API, and renders the
     table / input. Pagination (`next`/`prev`), text-search debounce (300ms), and
     `data-skip`/`data-total` bookkeeping all live in `iterateTable()`.
   - Field-rendering syntax in `fields` is interpreted in `generateRowTable()`: `*` (pagename),
     `*link`, `#link` (link with `@…` and `Namespace:` stripped from the label), `*score`,
     `~field` (render field as a link), `#field`. `prefix` prepends `value:` to a field;
     `prefixurl` (`field:urlbase,…`) and `prefixcondurl`
     (`field:otherfield@value=urlbase,…`, parsed by `getPrefixCondUrl()`/`assignUrlPrefix()`) choose
     the link base for `~`/`#` fields. Field values are HTML-escaped before insertion into `<td>`
     unless the field name appears in the table's `raw` list - see "Known behaviour changes" below.

2. API modules (`action=...` endpoints), all under `includes/Api/`
   - `ApiOpenSearch_Query` (`opensearch-query`) → `OpenSearch_Index::processIndex` → one `_search`
     call built by `OpenSearch_QueryBuilder::buildViewQuery`. Handles `key`, `keys`, `startkey`,
     `endkey` as filters against the query config's `keyfield`; `limit`/`skip` as `size`/`from`;
     an optional `groupfield` terms aggregation surfaced as a `groups` sibling of `results` (the
     CouchDB-era parallel `group=true` reduce request is gone - `hits.total.value` with
     `track_total_hits: true` already is the exact matching count).
   - `ApiOpenSearch_Search` (`opensearch-search`) → `OpenSearch_Search::processIndex` → `q` becomes
     a `query_string` query over the config's `textfields`. `full=1` pages via `search_after` over
     a deterministic `_score desc, _id asc` sort (replacing CouchDB-Lucene/Nouveau's `bookmark`
     recursion), capped by `queries[$db][$index]['maxFullPages']` (default 25, was a hardcoded 25).
     A `query_string` parse error (e.g. a debounced, partially-typed `foo AND`) is turned into
     `count: 0` plus a `warning`, not a hard API failure.
   - `ApiOpenSearch_Document` (`opensearch-document`) → `OpenSearch_Document::processDocument` →
     `GET /<index>/_doc/<id>`, using the separate `document[$db]` connection block. A 404 becomes
     `count: 0`, not a decode failure.
   - `ApiCouchDB_Query`, `ApiCouchDB_Query_Lucene`, `ApiCouchDB_Query_Nouveau`,
     `ApiCouchDB_Document` are thin subclasses of the three modules above (Lucene and Nouveau both
     alias `ApiOpenSearch_Search`, since `query_string` covers what both did), overriding only
     `isDeprecated()` to return `true`. They add no logic - there is one implementation per
     behaviour, matching the actions declared in `extension.json`'s `APIModules`.
   - All three current modules resolve `_id` → MediaWiki page via `WikiPageFactory::newFromID` and
     set `pagename`; `ApiOpenSearch_Search` falls back to `map[$db][$index]['pagename']` when
     `newFromID` returns nothing, guarding every access with `is_object`/`property_exists` (the
     pattern the old Nouveau module used; the old Lucene module dereferenced `$outcome->rows`
     unguarded, which no longer exists as a code path).

`includes/OpenSearch_Connection.php` is the one place that resolves config and talks to the
cluster: `getConfig()` merges `$wgCouchDB_Query` into `$wgOpenSearch_Query` (new wins) with a
`wfDeprecated()` call, cached per-request; `resolveConnectionParams( $block, $db )` reads a
`params[$db]` or `document[$db]` block; `request()` issues the HTTP call via
`MediaWikiServices::getInstance()->getHttpRequestFactory()->create(...)`, sends
`Authorization: Basic ...` only when a username is configured, and returns a typed
`{ok, httpCode, data, error}` array rather than a warning-emitting `false`. Credentials never
appear in a URL (the old CouchDB code built `user:pass@host` URLs).

## Configuration surface

Config lives entirely in `$wgOpenSearch_Query` (default skeleton in `extension.json` under
`config.OpenSearch_Query.value`). The four top-level keys the code actually reads:

- `params[$db]` - connection info (`host`, `port`, `protocol`, `username`, `password`, `timeout`,
  `connectTimeout`) for view/search queries. Looked up by the `db` request param, not necessarily
  any field inside the block itself.
- `queries[$db][$index]` - either a plain string (shorthand for `[ 'index' => <string> ]`) or a
  block: `index` (required), plus `keyfield`, `groupfield`, `filter`, `sort`, `source` for
  view-equivalent queries, or `textfields`, `lenient`, `allowLeadingWildcard`, `maxFullPages` for
  full-text queries. `OpenSearch_Index` and `OpenSearch_Search` both resolve `index` against this
  same map via the shared `OpenSearch_Index::resolveQueryConfig()` - the API action called, not the
  config, decides how the response is parsed.
- `document[$db]` - separate connection block (own `host`/`port`/`protocol`/`index`) used only by
  `OpenSearch_Document`.
- `map[$db][$index]['pagename']` - fallback for resolving a page name from a `_source` field, used
  by `ApiOpenSearch_Search` only.

If `$wgOpenSearch_Query` is unset and the legacy `$wgCouchDB_Query` is set, `getConfig()` uses it
directly (still logging the deprecation notice); if both are set, `array_replace_recursive` merges
them with `$wgOpenSearch_Query` winning per-key. A merged config only works if `queries[$db][$index]`
has already been converted to block form - `$wgCouchDB_Query`'s CouchDB URL-path strings are not
valid here.

## Things worth knowing before editing

- The escaping fix in `generateRowTable()` (HTML-escape field values by default, `data-raw`
  opt-out) is a deliberate, user-confirmed behaviour change from the original CouchDB_Query, which
  interpolated `result.fields[field]` straight into `<td>` HTML unescaped. If a page relies on raw
  markup inside an indexed field, add that field name to `raw=field1,field2` on the table (there is
  no parser-function whitelist entry for `raw` yet - see the Architecture section above).
- `full=1` results are cached in `sessionStorage`, keyed by `db:index:type:queryValue` (built in
  `iterateTable()`), not `localStorage`. The original code called `localStorage.clear()` on every
  successful AJAX response regardless of `full`, which wiped unrelated `localStorage` data for the
  entire wiki origin and made two full-text tables on the same page clobber each other's cache.
  Clicking Next/Previous in `full` mode still re-fires the AJAX request (matching original
  behaviour) but only ever renders from the session-cached array, so a superseded fetch is wasted
  bandwidth, not a bug to "fix" in isolation.
- `subsTextQuery()` escapes the full `query_string` reserved set
  (`+ - = && || > < ! ( ) { } [ ] ^ " ~ * ? : \ /`) via `escapeQueryString()`, not just `:` like the
  original. This applies uniformly to both view-equivalent key substitution and full-text `q`
  substitution, matching the original's placement.
- `window.couchjsfunc` is superseded by `window.opensearchjsfunc` for the `javascript:` query
  prefix dispatch table; `subsTextQuery()` checks the new name first and falls back to the old one,
  so wikis with custom functions registered under the old name don't need to change anything.
- `.jshintrc` now sets `esversion: 6` (was `es3`, which never matched the `let`/`for...of` already
  in the file) and the previously-undeclared `part` in `getPrefixCondUrl` is now a proper
  `let`-scoped loop variable. `jshint libs/main.js` should come back clean; treat a new failure as
  a real regression, unlike before.
- `CLAUDE.md`, `CLAUDE.local.md` and `.claude/` are listed in `.gitignore` - this file is
  deliberately not tracked.
- `docs/migration-from-couchdb.md`'s deprecation timeline ("next major version", "first major
  version after that") is the author's intent, not a Claude task - don't act on it unless asked.

## Common commands

There is no test runner and no CI in this repo, and `composer.json` declares no scripts or dev
dependencies. The only practical operations:

```bash
# install into a MediaWiki tree
ln -s "$PWD" /path/to/mediawiki/extensions/OpenSearch_Query
# then in LocalSettings.php:
#   wfLoadExtension( 'OpenSearch_Query' );

# hit the API endpoints directly for debugging
curl 'https://wiki.example/api.php?action=opensearch-query&db=mydb&index=coords&key="chr1"&fields=start,end&format=json'
curl 'https://wiki.example/api.php?action=opensearch-search&db=mydb&index=text&q=foo&format=json'
curl 'https://wiki.example/api.php?action=opensearch-document&db=mydb&key=docid&format=json'

# legacy aliases - same params, deprecation warning in the response
curl 'https://wiki.example/api.php?action=couchdb-query&db=mydb&index=coords&format=json'
curl 'https://wiki.example/api.php?action=couchdb-lucene-query&db=mydb&index=text&q=foo&format=json'
curl 'https://wiki.example/api.php?action=couchdb-nouveau-query&db=mydb&index=text&q=foo&format=json'
curl 'https://wiki.example/api.php?action=couchdb-document&db=mydb&key=docid&format=json'
```

JS lint (if jshint is installed globally): `jshint libs/main.js` - `.jshintrc` enforces
`esversion: 6`, `eqeqeq`, `bitwise`, `unused`, browser globals plus `mediaWiki` and `jQuery`.

PHP syntax check (no linter config exists, but plain syntax checking works and catches typos):
`find . -name '*.php' -exec php -l {} \;`
