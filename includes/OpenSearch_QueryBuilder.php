<?php

class OpenSearch_QueryBuilder {

	/**
	 * A queries[$db][$index] entry may be a plain string (shorthand for [ 'index' => $string ]).
	 */
	public static function normaliseQueryConfig( $config ) {
		if ( is_string( $config ) ) {
			return [ 'index' => $config ];
		}
		return $config;
	}

	/**
	 * View-equivalent query: key/keys/startkey/endkey filters against keyfield, optional groupfield
	 * terms aggregation as the reduce equivalent.
	 */
	public static function buildViewQuery( array $params, array $queryConfig ) {

		$filters = [];
		$keyfield = $queryConfig['keyfield'] ?? null;

		if ( $keyfield !== null ) {

			if ( ! empty( $params['keys'] ) ) {
				$keys = json_decode( $params['keys'], true );
				if ( is_array( $keys ) ) {
					$filters[] = [ 'terms' => [ $keyfield => array_values( $keys ) ] ];
				}
			} elseif ( ! empty( $params['key'] ) ) {
				$filters[] = [ 'term' => [ $keyfield => trim( $params['key'], '"' ) ] ];
			}

			$range = [];
			if ( ! empty( $params['startkey'] ) ) {
				$range['gte'] = trim( $params['startkey'], '"' );
			}
			if ( ! empty( $params['endkey'] ) ) {
				$range['lte'] = trim( $params['endkey'], '"' );
			}
			if ( $range !== [] ) {
				$filters[] = [ 'range' => [ $keyfield => $range ] ];
			}
		}

		if ( ! empty( $queryConfig['filter'] ) ) {
			$filters[] = $queryConfig['filter'];
		}

		$body = [
			'query' => $filters !== [] ? [ 'bool' => [ 'filter' => $filters ] ] : [ 'match_all' => new stdClass() ],
			'track_total_hits' => true,
		];

		self::applyPaging( $body, $params );
		self::applySource( $body, $params, $queryConfig );

		if ( ! empty( $queryConfig['sort'] ) ) {
			$body['sort'] = $queryConfig['sort'];
		} elseif ( $keyfield !== null ) {
			$body['sort'] = [ [ $keyfield => 'asc' ] ];
		} else {
			$body['sort'] = [ '_doc' ];
		}

		if ( ! empty( $queryConfig['groupfield'] ) ) {
			$body['aggs'] = [
				'groups' => [
					'terms' => [ 'field' => $queryConfig['groupfield'], 'size' => 1000 ],
				],
			];
		}

		return $body;
	}

	/**
	 * Full-text query_string search over textfields. Always sorts by score then _id so search_after
	 * paging (full=1) has a deterministic, unique last key.
	 */
	public static function buildSearchQuery( array $params, array $queryConfig ) {

		$queryStringClause = [
			'query' => $params['q'] ?? '',
			'fields' => $queryConfig['textfields'] ?? [],
		];

		if ( ! empty( $queryConfig['lenient'] ) ) {
			$queryStringClause['lenient'] = true;
		}

		$queryStringClause['allow_leading_wildcard'] = ! empty( $queryConfig['allowLeadingWildcard'] );

		$query = [ 'query_string' => $queryStringClause ];

		if ( ! empty( $queryConfig['filter'] ) ) {
			$query = [
				'bool' => [
					'must' => [ $query ],
					'filter' => [ $queryConfig['filter'] ],
				],
			];
		}

		$body = [
			'query' => $query,
			'track_total_hits' => true,
			'sort' => [
				[ '_score' => 'desc' ],
				[ '_id' => 'asc' ],
			],
		];

		self::applyPaging( $body, $params );

		// Full-text results always carry a fields object (parity with the Lucene/Nouveau response
		// shape), so _source is only narrowed by config, never suppressed.
		if ( ! empty( $queryConfig['source'] ) ) {
			$body['_source'] = $queryConfig['source'];
		}

		if ( ! empty( $params['searchAfter'] ) ) {
			$body['search_after'] = $params['searchAfter'];
		}

		return $body;
	}

	private static function applyPaging( array &$body, array $params ) {
		if ( ! empty( $params['limit'] ) ) {
			$body['size'] = (int)$params['limit'];
		}
		if ( array_key_exists( 'skip', $params ) && $params['skip'] !== '' && $params['skip'] !== null ) {
			$body['from'] = (int)$params['skip'];
		}
	}

	/**
	 * Fields are always served out of _source, so _source is only suppressed when neither `fields`
	 * nor `include_docs` was requested - mirroring the CouchDB view, where include_docs gated the
	 * heavier document fetch.
	 */
	private static function applySource( array &$body, array $params, array $queryConfig ) {

		if ( empty( $params['fields'] ) && empty( $params['include_docs'] ) ) {
			$body['_source'] = false;
			return;
		}

		if ( ! empty( $queryConfig['source'] ) ) {
			$body['_source'] = $queryConfig['source'];
		}
	}

}
