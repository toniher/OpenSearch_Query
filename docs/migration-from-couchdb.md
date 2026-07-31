# Migrating from CouchDB_Query to OpenSearch_Query

This extension was renamed from `CouchDB_Query` to `OpenSearch_Query` when its backing store moved
from CouchDB (map/reduce views, CouchDB-Lucene, CouchDB Nouveau) to OpenSearch. Existing wiki pages
and API callers keep working unchanged: `#CouchDB_Query_table`, `#CouchDB_Query_field`, the
`couchdb-*` API actions, and the `$wgCouchDB_Query` global are all still recognised, as aliases of
the new names.

## What actually changed

Nothing wiki-facing. The parser functions render the same markup (both the new and legacy CSS
classes are emitted on every element), the JavaScript renders the same table/field UI, and the API
response envelope (`{ status, count, results[] }`, results shaped `{ id, pagename, fields }` or
`{ id, score, pagename, fields }`) is unchanged.

What changed is everything behind that envelope: PHP now builds an OpenSearch query DSL and talks
to the cluster over `MediaWiki\Http\HttpRequestFactory`, instead of building a CouchDB URL path and
calling `file_get_contents`.

## Semantic mapping

| CouchDB behaviour | OpenSearch replacement |
|---|---|
| `db` request param selects `params[$db]` connection | unchanged (still a connection selector, not an index name) |
| `queries[$db][$index]` = URL path string | `queries[$db][$index]` = config block (or a plain string, still accepted, as shorthand for `[ 'index' => <string> ]`) |
| view row `id` | hit `_id` |
| `row.value.<f>`, falling back to `row.doc.<f>` | `_source.<f>` (single lookup, no fallback needed) |
| `total_rows` | `hits.total.value`, with `track_total_hits: true` so the count is exact |
| `key="X"` | `term` filter on `keyfield` |
| `keys=["a","b"]` | `terms` filter on `keyfield` |
| `startkey` / `endkey` | `range` on `keyfield` with `gte` / `lte` |
| implicit sort-by-view-key | explicit `sort: [ { <keyfield>: asc } ]`, else `_doc` |
| `limit` / `skip` | `size` / `from` (capped at 10,000 total by OpenSearch outside `full=1`) |
| `include_docs=true` | `_source` is returned whenever `fields` or `include_docs` is requested; suppressed otherwise |
| second HTTP call with `group=true` to get a real count | one `_search` carrying an optional `aggs.groups` terms aggregation, surfaced as a `groups` sibling of `results` |
| Lucene / Nouveau `q` | `query_string` over the configured `textfields` |
| `score` | `_score` |
| Lucene/Nouveau `fields` response object | `_source`, narrowed by a `source` include list if configured |
| `bookmark` recursion for `full=1` | `search_after` over a deterministic `_score desc, _id asc` sort |
| `GET /<db>/<key>` | `GET /<index>/_doc/<id>` |

Two consequences worth knowing:

- The old CouchDB view module fired a *second* HTTP request (`group=true`) purely to get an exact
  count for a keyed query, because a view's `total_rows` counted the whole view rather than the
  matching key. OpenSearch's `hits.total.value` already is the matching count, so that extra round
  trip is gone.
- CouchDB-Lucene and CouchDB Nouveau were two separate PHP classes with slightly different response
  shapes; both collapse into `OpenSearch_Search`, since `query_string` covers what both did. Nouveau
  never supported `skip` (bookmark-only paging); OpenSearch's `from`/`size` restores working
  Next/Previous for full-text results.

## Config schema

```php
$wgOpenSearch_Query = [
    'params' => [
        'mydb' => [
            'host' => 'localhost', 'port' => 9200, 'protocol' => 'https',
            'username' => '', 'password' => '',   // omit/empty for an open cluster
            'timeout' => 10, 'connectTimeout' => 5,
        ],
    ],
    'queries' => [
        'mydb' => [
            'coords' => [
                'index' => 'mydb-coords', 'keyfield' => 'chrom.keyword',
                'groupfield' => 'chrom.keyword',
                'filter' => [ 'term' => [ 'type' => 'coord' ] ],
                'sort' => [ [ 'chrom.keyword' => 'asc' ] ],
                'source' => [ 'chrom', 'start', 'end' ],
            ],
            'text' => [
                'index' => 'mydb-docs',
                'textfields' => [ 'title^3', 'body' ],
                'source' => [ 'title', 'body', 'pagename' ],
            ],
        ],
    ],
    'document' => [
        'mydb' => [ 'host' => '...', 'port' => 9200, 'index' => 'mydb-docs' ],
    ],
    'map' => [ 'mydb' => [ 'text' => [ 'pagename' => 'title' ] ] ],
];
```

If `$wgOpenSearch_Query` is left unset and `$wgCouchDB_Query` is still set, the old global is merged
in automatically (`$wgOpenSearch_Query` wins on any overlapping key) and a deprecation notice is
logged once. This lets you migrate `LocalSettings.php` at your own pace, but the merged config only
makes sense if `queries[$db][$index]` values have already been converted to the block form above -
a CouchDB URL path string is not a valid OpenSearch query config.

## Worked example

Before:

```php
wfLoadExtension( 'CouchDB_Query' );

$wgCouchDB_Query['params']['mydb']['host']     = 'localhost';
$wgCouchDB_Query['params']['mydb']['port']     = 5984;
$wgCouchDB_Query['params']['mydb']['protocol'] = 'http';
$wgCouchDB_Query['params']['mydb']['username'] = 'myuser';
$wgCouchDB_Query['params']['mydb']['password'] = 'mypassword';

$wgCouchDB_Query['queries']['mydb']['coords'] = '/mydb/_design/search/_view/coords';
$wgCouchDB_Query['queries']['mydb']['text']   = '/_fti/local/mydb/_design/luceneindex/by_text';
```

After:

```php
wfLoadExtension( 'OpenSearch_Query' );

$wgOpenSearch_Query['params']['mydb']['host']     = 'localhost';
$wgOpenSearch_Query['params']['mydb']['port']     = 9200;
$wgOpenSearch_Query['params']['mydb']['protocol'] = 'https';
$wgOpenSearch_Query['params']['mydb']['username'] = 'myuser';
$wgOpenSearch_Query['params']['mydb']['password'] = 'mypassword';

$wgOpenSearch_Query['queries']['mydb']['coords'] = [
    'index' => 'mydb-coords', 'keyfield' => 'chrom.keyword',
];
$wgOpenSearch_Query['queries']['mydb']['text'] = [
    'index' => 'mydb-docs', 'textfields' => [ 'title^3', 'body' ],
];
```

Wiki pages need no changes: `{{#CouchDB_Query_table: index=coords | db=mydb | type=couchdb-query }}`
keeps rendering exactly as before, now backed by OpenSearch. New pages should use the canonical
`#OpenSearch_Query_table` / `type=opensearch-query` spellings.

## Deprecation timeline for the `couchdb-*` aliases

- **Now**: `#CouchDB_Query_table`, `#CouchDB_Query_field`, `$wgCouchDB_Query`, and the
  `couchdb-query` / `couchdb-lucene-query` / `couchdb-nouveau-query` / `couchdb-document` API
  actions all work, unchanged. The four API actions are marked `isDeprecated()`, so API responses
  carry a deprecation warning.
- **Next major version**: aliases remain, but new features (if any) land only on the
  `opensearch-*` / `OpenSearch_Query_*` spellings.
- **First major version after that**: the `couchdb-*` API action aliases and the
  `$wgCouchDB_Query` fallback merge are removed. `#CouchDB_Query_table` / `#CouchDB_Query_field`
  remain as magic-word aliases indefinitely, since rewriting wikitext at scale is a much bigger ask
  than updating `LocalSettings.php`.

No specific dates are attached; this extension has no release train. Treat "next major version" as
"the next time this extension's version number gets a major bump."
