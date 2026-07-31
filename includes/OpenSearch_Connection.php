<?php

class OpenSearch_Connection {

	private static $mergedConfig = null;

	/**
	 * Resolves $wgOpenSearch_Query, merging in $wgCouchDB_Query as a deprecated fallback.
	 * New keys in $wgOpenSearch_Query win over the legacy global.
	 *
	 * @return array
	 */
	public static function getConfig() {

		if ( self::$mergedConfig !== null ) {
			return self::$mergedConfig;
		}

		$new = $GLOBALS['wgOpenSearch_Query'] ?? null;
		$legacy = $GLOBALS['wgCouchDB_Query'] ?? null;

		if ( $new === null && $legacy !== null ) {
			wfDeprecated( '$wgCouchDB_Query (use $wgOpenSearch_Query)', '1.0', 'OpenSearch_Query' );
			self::$mergedConfig = $legacy;
		} elseif ( $new !== null && $legacy !== null ) {
			self::$mergedConfig = array_replace_recursive( $legacy, $new );
		} else {
			self::$mergedConfig = $new ?? [];
		}

		return self::$mergedConfig;
	}

	/**
	 * @param string $block 'params' or 'document'
	 * @param string $db
	 * @return array connection params, or [] if not configured
	 */
	public static function resolveConnectionParams( $block, $db ) {
		$config = self::getConfig();
		return $config[$block][$db] ?? [];
	}

	/**
	 * Issue an HTTP request against a resolved connection block. Credentials are sent only via the
	 * Authorization header, never embedded in the URL.
	 *
	 * @param array $connectionParams host/port/protocol/username/password/timeout/connectTimeout
	 * @param string $path path appended to the base URL, e.g. "/myindex/_search"
	 * @param string $method
	 * @param array|null $body decoded body, JSON-encoded as postData when present
	 * @return array{ok:bool,httpCode:int,data:mixed,error:?string}
	 */
	public static function request( array $connectionParams, $path, $method = 'GET', $body = null ) {

		$protocol = $connectionParams['protocol'] ?? 'https';
		$host = $connectionParams['host'] ?? '';
		$port = $connectionParams['port'] ?? '';
		$portstr = ( $port !== '' && $port !== null ) ? ':' . $port : '';

		$url = $protocol . '://' . $host . $portstr . $path;

		$options = [
			'method' => $method,
			'timeout' => $connectionParams['timeout'] ?? 10,
			'connectTimeout' => $connectionParams['connectTimeout'] ?? 5,
		];

		if ( $body !== null ) {
			$options['postData'] = json_encode( $body );
		}

		$req = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$url,
			$options,
			__METHOD__
		);

		$req->setHeader( 'Content-Type', 'application/json' );

		if ( ! empty( $connectionParams['username'] ) ) {
			$req->setHeader(
				'Authorization',
				'Basic ' . base64_encode( $connectionParams['username'] . ':' . ( $connectionParams['password'] ?? '' ) )
			);
		}

		$status = $req->execute();
		$httpCode = $req->getStatus();

		if ( ! $status->isOK() && $httpCode === 0 ) {
			// No HTTP response at all: DNS failure, connection refused, timeout.
			return [
				'ok' => false,
				'httpCode' => 0,
				'data' => null,
				'error' => \Status::wrap( $status )->getWikiText( false, false, 'en' ),
			];
		}

		$content = $req->getContent();
		$data = null;

		if ( $content !== null && $content !== '' ) {
			$data = json_decode( $content );
			if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
				return [
					'ok' => false,
					'httpCode' => $httpCode,
					'data' => null,
					'error' => 'invalid-json',
				];
			}
		}

		return [
			'ok' => true,
			'httpCode' => $httpCode,
			'data' => $data,
			'error' => null,
		];
	}

}
