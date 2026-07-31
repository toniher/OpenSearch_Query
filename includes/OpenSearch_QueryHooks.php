<?php

class OpenSearch_QueryHooks {

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook(
			'OpenSearch_Query_table',
			[ OpenSearch_Query::class, 'process_OpenSearch_Query_table' ],
			SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			'OpenSearch_Query_field',
			[ OpenSearch_Query::class, 'process_OpenSearch_Query_field' ],
			SFH_OBJECT_ARGS
		);
	}
}
