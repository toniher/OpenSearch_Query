Extension for querying OpenSearch indices within a MediaWiki instance.

Formerly known as CouchDB_Query. See [docs/migration-from-couchdb.md](docs/migration-from-couchdb.md)
if you are upgrading from the CouchDB-backed version.

## Requirements

- MediaWiki 1.35 or later
- An OpenSearch cluster (2.x or later)

## Installation

1. Clone or download this extension into your MediaWiki `extensions/` directory:

   ```
   git clone <repo-url> extensions/OpenSearch_Query
   ```

2. Add the following to the **bottom** of your `LocalSettings.php`:

   ```php
   wfLoadExtension( 'OpenSearch_Query' );
   ```

3. Configure the OpenSearch connection in `LocalSettings.php` after the `wfLoadExtension` line:

   ```php
   $wgOpenSearch_Query['params']['mydb']['host']           = 'localhost';
   $wgOpenSearch_Query['params']['mydb']['port']           = 9200;
   $wgOpenSearch_Query['params']['mydb']['protocol']       = 'https';
   $wgOpenSearch_Query['params']['mydb']['username']       = 'myuser';   // omit for an open cluster
   $wgOpenSearch_Query['params']['mydb']['password']       = 'mypassword';
   $wgOpenSearch_Query['params']['mydb']['timeout']        = 10;        // read timeout, seconds
   $wgOpenSearch_Query['params']['mydb']['connectTimeout'] = 5;
   ```

4. Define the named queries your wiki will use. A view-equivalent query names an index and the
   field that plays the role of a CouchDB view key; a full-text query names an index and the
   fields to search:

   ```php
   $wgOpenSearch_Query['queries']['mydb']['coords'] = [
       'index'      => 'mydb-coords',      // OpenSearch index or alias
       'keyfield'   => 'chrom.keyword',    // key / keys / startkey / endkey target
       'groupfield' => 'chrom.keyword',    // optional: terms aggregation, exposed as `groups`
       'filter'     => [ 'term' => [ 'type' => 'coord' ] ],  // optional constant filter
       'sort'       => [ [ 'chrom.keyword' => 'asc' ] ],     // optional, defaults to keyfield asc
       'source'     => [ 'chrom', 'start', 'end' ],          // optional _source includes
   ];

   $wgOpenSearch_Query['queries']['mydb']['text'] = [
       'index'      => 'mydb-docs',
       'textfields' => [ 'title^3', 'body' ],   // query_string fields, boosts allowed
       'source'     => [ 'title', 'body', 'pagename' ],
       'lenient'    => false,                    // true: don't error on query_string syntax
       'allowLeadingWildcard' => false,
   ];
   ```

   `queries[$db][$index]` also accepts a plain string, treated as `[ 'index' => <string> ]`, for
   the simplest case.

5. Single-document fetches (`#OpenSearch_Query_field`-style lookups by id) use a separate
   connection block, since it is often a different index or cluster:

   ```php
   $wgOpenSearch_Query['document']['mydb'] = [
       'host'     => 'localhost',
       'port'     => 9200,
       'protocol' => 'https',
       'index'    => 'mydb-docs',
   ];
   ```

6. When a hit's `_id` does not resolve to a MediaWiki page id (full-text results from a document
   store keyed some other way), name the `_source` field to use as the page name instead:

   ```php
   $wgOpenSearch_Query['map']['mydb']['text']['pagename'] = 'title';
   ```

## Usage

Two parser functions are available in wiki pages:

### `{{#OpenSearch_Query_table:}}`

Renders a dynamic table populated from an OpenSearch query.

```
{{#OpenSearch_Query_table: index=coords | db=mydb | type=opensearch-query | limit=25 | fields=start,end }}
```

| Parameter | Description |
|---|---|
| `index` | Query name, resolved against `queries[$db]` |
| `db` | Connection identifier, resolved against `params[$db]` (default: current wiki's DB name) |
| `type` | API action to call: `opensearch-query` (view-equivalent) or `opensearch-search` (full text). The legacy `couchdb-query`, `couchdb-lucene-query`, `couchdb-nouveau-query` values still work. |
| `limit` | Maximum rows to return (default: 25) |
| `fields` | Comma-separated list of fields to display. Supports `*` (page name), `*link`, `#link` (link with `@...` and `Namespace:` stripped from the label), `*score`, `~field` (field rendered as a link), `#field` (as `~field`, but also strips `@...`/`Namespace:` from the label) |
| `raw` | Comma-separated list of field names to render unescaped. All other field values are HTML-escaped by default |
| `query` | Query template. `$1` is replaced with the current search text; `javascript:funcName` dispatches to `window.opensearchjsfunc.funcName(text)` (falls back to the legacy `window.couchjsfunc`) |
| `text` | Initial value for the search input |
| `start` / `end` | Passed through as `startkey` / `endkey` for view-equivalent queries |
| `header` | Column header label(s), comma-separated (default: `Page name`) |
| `class` | CSS class for the table (default: `wikitable sortable jquery-tablesorter`) |
| `prefix` | `field:value,...` - prepends `value:` to the named field before display |
| `prefixurl` | `field:urlbase,...` - link base to use for `~field` / `#field` |
| `prefixcondurl` | `field:otherfield@value=urlbase,...` - link base for `~field` / `#field`, chosen by the value of another field in the same result |
| `extra` | Comma-separated list of CSS selectors pointing at `{{#OpenSearch_Query_field}}` elements elsewhere on the page whose values are appended to the query |
| `full` | Set to `1` to retrieve the complete result set (paged internally via `search_after`) and paginate client-side |

### `{{#OpenSearch_Query_field:}}`

Renders an input field that can be wired into a table's `extra` list.

```
{{#OpenSearch_Query_field: tag=input | type=text | query=field:$1 | id=myfield }}
```

| Parameter | Description |
|---|---|
| `tag` | HTML tag to render (`input`, `select`, etc.) |
| `type` | Input type attribute |
| `query` | Query fragment substituted with this field's value and appended to the parent table's query |
| `values` | Predefined values, comma-separated (for `select`) |
| `id` | Element ID |
| `class` | CSS class (currently not applied to the rendered element - see docs/migration-from-couchdb.md) |
| `default` | Default selected value (for `select`) |

## API Modules

| Module | Action | Description |
|---|---|---|
| `opensearch-query` | `api.php?action=opensearch-query` | View-equivalent query: `key` / `keys` / `startkey` / `endkey` against `keyfield` |
| `opensearch-search` | `api.php?action=opensearch-search` | Full-text `query_string` search over `textfields` |
| `opensearch-document` | `api.php?action=opensearch-document` | Retrieve a single document by id |
| `couchdb-query`, `couchdb-lucene-query`, `couchdb-nouveau-query`, `couchdb-document` | same paths | Deprecated aliases of the above, kept for existing callers. Marked `isDeprecated()`. |

`opensearch-query` params: `index`, `db`, `key`, `keys` (JSON array), `startkey`, `endkey`, `fields`,
`limit`, `skip`, `include_docs`. Response: `{ status, count, results[], groups[]? }`, each result
`{ id, pagename, fields }`.

`opensearch-search` params: `index`, `db`, `q`, `limit`, `skip`, `sort`, `full`, `aggs`. Response:
`{ status, count, results[], warning? }`, each result `{ id, score, pagename, fields }`. A
malformed `q` (e.g. partially typed `query_string` syntax) yields `count: 0` plus a `warning`,
not an API error.

`aggs` (optional, JSON object) adds an OpenSearch `aggs` body to the search and returns its raw
result under `aggregations` (no hits: `size` is forced to 0, so repeats are served from the shard
request cache). Only named queries with `'aggs' => true` accept it (`aggs-not-allowed`
otherwise); anything mentioning `script` is refused (`aggs-invalid`).

`opensearch-document` params: `db`, `key`. Response: `{ status, count, results[] }`, the single
result being the document's `_source` flattened alongside its `_id`.

### TODO

* Migrate JavaScript to use a proper OpenSearch JS client instead of hand-built query_string
