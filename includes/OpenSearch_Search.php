<?php

class OpenSearch_Search {

	private const DEFAULT_MAX_FULL_PAGES = 25;

	/**
	 * @param array $params request params: index, db, q, limit, skip, sort, full, aggs
	 * @return array{ok:bool,httpCode:int,data:mixed,error:?string,warning:?string}|null
	 */
	public static function processIndex( array $params ) {

		$queryConfig = OpenSearch_Index::resolveQueryConfig( $params );
		if ( $queryConfig === null ) {
			return null;
		}

		$connectionParams = OpenSearch_Connection::resolveConnectionParams( 'params', $params['db'] );
		$path = '/' . $queryConfig['index'] . '/_search';

		if ( ! empty( $params['aggs'] ) ) {
			if ( empty( $queryConfig['aggs'] ) ) {
				return [ 'ok' => false, 'httpCode' => 0, 'data' => null, 'error' => 'aggs-not-allowed', 'warning' => null ];
			}
			$aggs = json_decode( $params['aggs'], true );
			// Re-encoded so \u-escapes can't hide it: no scripts of any kind (script, scripted_metric, ...).
			if ( ! is_array( $aggs ) || ! $aggs || stripos( json_encode( $aggs ), 'script' ) !== false ) {
				return [ 'ok' => false, 'httpCode' => 0, 'data' => null, 'error' => 'aggs-invalid', 'warning' => null ];
			}
			$dsl = OpenSearch_QueryBuilder::buildSearchQuery( $params, $queryConfig );
			// Buckets only: size 0 also lets OpenSearch serve repeats from its shard request cache.
			unset( $dsl['sort'], $dsl['_source'], $dsl['from'] );
			$dsl['size'] = 0;
			$dsl['aggs'] = $aggs;
			return self::guardParseError( OpenSearch_Connection::request( $connectionParams, $path, 'POST', $dsl ) );
		}

		if ( empty( $params['full'] ) ) {
			$dsl = OpenSearch_QueryBuilder::buildSearchQuery( $params, $queryConfig );
			return self::guardParseError( OpenSearch_Connection::request( $connectionParams, $path, 'POST', $dsl ) );
		}

		$maxPages = $queryConfig['maxFullPages'] ?? self::DEFAULT_MAX_FULL_PAGES;

		return self::retrievePaged( $connectionParams, $path, $params, $queryConfig, $maxPages );
	}

	/**
	 * full=1 paging via search_after, replacing CouchDB's bookmark recursion. Stops on an empty
	 * page, a failed/errored response, or the page cap.
	 */
	private static function retrievePaged( array $connectionParams, $path, array $params, array $queryConfig, $maxPages ) {

		$aggregate = null;
		$searchAfter = null;
		$pages = 0;

		do {
			$pageParams = $params;
			if ( $searchAfter !== null ) {
				$pageParams['searchAfter'] = $searchAfter;
			}

			$dsl = OpenSearch_QueryBuilder::buildSearchQuery( $pageParams, $queryConfig );
			$response = self::guardParseError(
				OpenSearch_Connection::request( $connectionParams, $path, 'POST', $dsl )
			);

			if ( ! $response['ok'] || $response['httpCode'] >= 400 || ! is_object( $response['data'] ) ) {
				return $aggregate ?? $response;
			}

			$hits = $response['data']->hits->hits ?? [];

			if ( $aggregate === null ) {
				$aggregate = $response;
			} else {
				$aggregate['data']->hits->hits = array_merge( $aggregate['data']->hits->hits, $hits );
			}

			$pages++;

			if ( count( $hits ) === 0 ) {
				break;
			}

			$last = end( $hits );
			$searchAfter = $last->sort ?? null;

		} while ( $searchAfter !== null && $pages < $maxPages );

		return $aggregate;
	}

	/**
	 * query_string has strict syntax; a partially-typed debounced query (e.g. "foo AND") returns a
	 * parse error. Treat that as zero results plus a warning, not a hard API failure.
	 */
	private static function guardParseError( array $response ) {

		if ( $response['ok'] && is_object( $response['data'] ) && isset( $response['data']->error ) ) {
			$reason = is_object( $response['data']->error ) && isset( $response['data']->error->reason )
				? $response['data']->error->reason
				: 'query-parse-error';

			return [
				'ok' => true,
				'httpCode' => $response['httpCode'],
				'data' => (object)[
					'hits' => (object)[ 'total' => (object)[ 'value' => 0 ], 'hits' => [] ],
				],
				'error' => null,
				'warning' => $reason,
			];
		}

		return $response;
	}

}
