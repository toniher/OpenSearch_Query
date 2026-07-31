/* jshint strict:true, browser:true */
/** Inspect all table instances **/
( function( $, mw ) {

	var inputsave = "";
	var timer, delay = 700;

	var TABLE_SELECTOR = ".opensearch-query-table, .couchdb-query-table";
	var INPUT_SELECTOR = ".opensearch-query-input, .couchdb-query-input";

	// Legacy `type` values (also literal API action names) map onto the two canonical
	// OpenSearch actions. Templates written for CouchDB keep working unchanged.
	var ACTION_ALIASES = {
		"couchdb-query": "opensearch-query",
		"couchdb-lucene-query": "opensearch-search",
		"couchdb-nouveau-query": "opensearch-search"
	};

	function normaliseAction( type ) {
		return ACTION_ALIASES.hasOwnProperty( type ) ? ACTION_ALIASES[ type ] : type;
	}

	$(document).ready(function(){
		iterateTable();
	});


	// Look for changes in the value
	$( TABLE_SELECTOR ).on( "propertychange change click keyup input paste", "input", function(event){

		var _this = $(this);
		clearTimeout(timer);

		timer = setTimeout(function() {
			// If value has changed...

			if ( inputsave !== $(_this).val()) {
				// Updated stored value
				inputsave = $(_this).val();
				var div = $( _this ).parents(TABLE_SELECTOR).first();

				$(div).data('text', inputsave );
				if ( inputsave.length > 2 ) {
					$(div).data('total', 0 );
					$(div).data('skip', 0 );
					iterateTable();
				}
			}
		}, 300, function() {});
	});

	// Next, previous, detecting data-total and data-limit, etc.
	$( TABLE_SELECTOR ).on( "click", ".bar > .next", function() {

		var div = $( this ).parents(TABLE_SELECTOR).first();
		var limit = parseInt( $(div).data('limit'), 10 );
		var skip = parseInt( $(div).data('skip'), 10 );

		$(div).data('skip', skip + limit );

		iterateTable();
	});

	$( TABLE_SELECTOR ).on( "click", ".prev", function() {

		var div = $( this ).parents(TABLE_SELECTOR).first();
		var limit = parseInt( $(div).data('limit'), 10 );
		var skip = parseInt( $(div).data('skip'), 10 );

		var newskip = skip - limit;
		if ( newskip < 0 ) {
			newskip = 0;
		}

		$(div).data('skip', newskip );

		iterateTable();
	});

	$( TABLE_SELECTOR ).on( "change click keyup input paste", INPUT_SELECTOR, function() {
		/** Trigger change **/
		iterateTable();
	});


	function iterateTable() {

		$( TABLE_SELECTOR ).each( function( i ) {
			var div = this;

			var limit = $(div).data('limit');
			var header = $(div).data('header');
			var tableclass = $(div).data('class');
			var fieldsp = $(div).data('fields');
			var query = $(div).data('query');
			var index = $(div).data('index');
			var type = $(div).data('type');
			var skip = $(div).data('skip');
			var db = $(div).data('db');
			var text = $(div).data('text');
			var extra = $(div).data('extra');
			var raw = $(div).data('raw');

			// Always talk to the canonical actions, regardless of which alias a page was written
			// against.
			type = normaliseAction( type );

			var prefix = processPrefix( $(div).data('prefix') );
			var prefixurl = processPrefix( $(div).data('prefixurl') );
			var prefixcondurl = $(div).data('prefixcondurl');

			var rawFields = [];
			if ( raw ) {
				var raws = raw.split(",");
				for ( var rr = 0; rr < raws.length; rr = rr + 1 ) {
					rawFields.push( raws[rr].trim() );
				}
			}

			var full = false;

			if ( $(div).data('full') ) {
				full = true;
			}

			// Stricty necessary
			if ( type !== "" && index !== "" && db !== "" ) {

				var params = {};

				// Let's put bar
				var bar = $(div).find(".bar").length;
				if ( bar === 0 ) {
					var inputval="";
					if ( text ) {
						inputval = " value='" + text + "'";
					}
					var input = "<input name='query' type='text' size=25"+inputval+">";
					var extraFields = "<div class='extra'></div>";
					$(div).append("<div class='bar'>"+input+extraFields+"</div>");
					processExtraFields( div, extra );
				}

				params["index"] = index;
				params["db"] = db;
				params["q"] = "";

				if ( full ) {
					params["full"] = true;
				}

				if ( limit !== "" && ! full ) {
					params["limit"] = limit;
				} else {
					params["limit"] = 200; // TO HANDLE bookmark for avoid this limit
        }

				if ( skip !== "" && ! full ) {
					params["skip"] = skip;
				}

				if ( type === "opensearch-search" ) {
					params["q"] = subsTextQuery( query, text );

					if ( extra ) {
						var extraq = "";
						extraq = retrieveExtraFields( div );
						params["q"] = params["q"] + extraq;
					}

				} else {
					if ( query.indexOf("[") > -1 ){
						params["keys"] = subsTextQuery( query, text );
					} else {
						params["key"] = subsTextQuery( query, text );
					}
				}

				if ( $(div).data('start') ) {
					params["start"] = $(div).data('start');
				}

				if ( $(div).data('end') ) {
					params["end"] = $(div).data('end');
				}

				// GET QUERY here
				params.action = type;
				params.format = "json";

				var fieldse = fieldsp.split(",");
				var fields = [];
				for ( var s = 0; s < fieldse.length; s = s + 1 ) {
					fields.push( fieldse[s].trim() );
				}

				var queryValue = params["q"] || params["keys"] || params["key"] || "";
				var cacheKey = "opensearch-query-full:" + db + ":" + index + ":" + type + ":" + queryValue;

				if ( params['q'].search(/\$\d/gi) < 1 ) {

					var posting = $.get( mw.config.get( "wgScriptPath" ) + "/api.php", params );
					posting.done(function( data ) {

						if ( data[type].status === "OK" ) {
							if ( data[type].count ) {
								$(div).data('total', data[type].count);

								var prev = ""; var next = ""; var count = "";
								$(div).find("table").remove();
								$(div).find(".bar > span").remove();

								if ( data[type].count > 0 ) {
									count = "<span class='count'>" + data[type].count + "</span>";
								}

								if ( ( ( data[type].count ) - parseInt( skip, 10 ) ) > parseInt( limit, 10 ) ) {
									next = "<span class='next'>Next</span>";
								}

								if ( parseInt( skip, 10 ) > 0 ) {
									prev = "<span class='prev'>Previous</span>";
								}

								$(div).find(".bar").first().append(count+prev+next);

								if ( data[type].results.length > 0 ) {

									if ( full ) {
										if ( ! sessionStorage.getItem( cacheKey ) ) {
											sessionStorage.setItem( cacheKey, JSON.stringify( data[type].results ) );
										}
									}

									let stuffResults = getResultsStuff( data[type].results, full, skip, limit, cacheKey );

									if ( stuffResults && stuffResults.length > 0 ) {
										var table = generateResultsTable( stuffResults, tableclass, header, fields, prefix, prefixurl, prefixcondurl, rawFields );
										$(div).append( table );
										// generateSMWTable( $(div).children("table"), fields );
										$(div).children("table").tablesorter(); //Let's make table sortable
									}
								}
							} else {
								$(div).find("table").remove();
								$(div).find(".bar > span").remove();
							}

						} else {
							$(div).find("table").remove();
							$(div).find(".bar > span").remove();
						}
					})
					.fail( function( data ) {
						console.log("Error!");
					});
				}
			}
		});
	}

	function getResultsStuff( results, full, skip, limit, cacheKey ) {

		let outcome = [];

		if ( full ) {

			var cached = sessionStorage.getItem( cacheKey );

			if ( cached ) {
				let jsonObj = JSON.parse( cached );

				if ( skip == "" ) {
					skip = 0;
				}

				if ( limit == "" ) {
					limit = 25;
				}

				let i = 0;
				let c = 0;
				for ( let j of jsonObj ) {

					if ( c >= limit ) {
						break;
					}

					if ( i >= skip ){
						outcome.push( j );
						c++;
					}

					i++;
				}


			} else {
				outcome = null;
			}
		} else {
			outcome = results;
		}

		return outcome;

	}

	// query_string reserves these characters; each must be individually backslash-escaped or the
	// cluster rejects the query outright (e.g. mid-typing input like "foo AND").
	function escapeQueryString( text ) {
		return text.replace( /([+\-=&|><!(){}[\]^"~*?:\\/])/g, "\\$1" );
	}

	function subsTextQuery( query, text ) {

		if ( text ) {
			if ( query.startsWith('javascript:') ) {
				// code for processing javascript
				var jsfunc = query;
				jsfunc = jsfunc.replace("javascript:", "");
				var dispatch = ( window.opensearchjsfunc && window.opensearchjsfunc[jsfunc] )
					? window.opensearchjsfunc
					: window.couchjsfunc;
				query = dispatch[jsfunc](text);
			} else {
				// Escape query_string reserved characters, then substitute
				text = escapeQueryString( text );
				query = query.replace( /\$1/g, text );
			}
		}

		return query;
	}

	function processPrefix( prefixstr ) {

		var prefix = {};

		if ( prefixstr ) {

			var prefixels = prefixstr.split(",");

			for ( var p=0; p < prefixels.length; p = p + 1 ) {

					var parts = prefixels[p].split(":");

					if ( parts.length === 2 ) {
						prefix[parts[0]] = parts[1];
					}
			}

		}

		return prefix;

	}

	function processExtraFields( div, extra ) {

		if ( extra ) {
			if ( div ) {
				var extras = extra.split(",");

				for ( var x = 0; x < extras.length; x = x + 1 ) {

					var fieldDef = $( extras[x].trim() ).first(); // Let's assume only one
					if ( fieldDef ) {
						$(div).find(".extra").append( processExtraField( fieldDef ) );
					}
				}

			}
		}
	}

	function processExtraField ( field ) {

		var out = "";
		if ( $(field).data('tag') ) {

			var tag = $(field).data('tag');

			var typestr = ""; var querystr = "";
			if ( $(field).data('type') ) {
				typestr = " type=\""+$(field).data('type')+"\"";
			}
			if ( $(field).data('query') ) {
				querystr = " data-query=\""+$(field).data('query')+"\"";
			}

			out = "<"+tag+typestr+querystr+" class='opensearch-query-input couchdb-query-input'>";

			var selected = "";
			if ( $(field).data('default') ) {
				selected = $(field).data('default');
			}

			if ( $(field).data('values') ) {
				var values = $(field).data('values').split(",");
				for ( var v = 0; v < values.length; v = v + 1 ) {
					var selectedstr = "";
					if ( selected == values[v] ) {
						selectedstr = " selected=selected";
					}
					out = out + "<option"+selectedstr+">"+values[v]+"</option>";
				}

				if ( values.length > 0 ) {
					out = out + "</"+tag+">";
				}
			}
		}

		return out;
	}

	function retrieveExtraFields( div ) {

		var extraq = "";
		$(div).find(INPUT_SELECTOR).each( function(i) {
			if ( $(this).data('query') ) {
				var val = $(this).val();
				if ( val && val !== '' ) {
					var subst = subsTextQuery( $(this).data('query'), val );
					extraq = extraq + " " + subst;
				}

			}
		});

		return extraq;
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, "&amp;" )
			.replace( /</g, "&lt;" )
			.replace( />/g, "&gt;" )
			.replace( /"/g, "&quot;" )
			.replace( /'/g, "&#39;" );
	}

	function generateResultsTable( results, tableclass, header, fields, prefix, prefixurl, prefixcondurl, rawFields ) {

		var table = "<table class='" + tableclass + "'>";
		table = table + "<thead><tr>";

		var headerstr = generateArrayTable( header, "th", " class=\"headerSort\" title=\"Sort ascending\"" );
		table = table + headerstr;

		table = table + "</tr></thead>";
		table = table + "<tbody>";

		for ( var r = 0; r < results.length; r = r + 1 ) {

			var rowstr = generateRowTable( results[r], fields, "td", prefix, prefixurl, prefixcondurl, rawFields );
			table = table + "<tr>" + rowstr + "</tr>";
		}

		table = table + "</tbody>";
		table = table + "</table>";

		return table;

	}

	function generateArrayTable( arraystr, tag, extra ){
		var str = "";
		var array = arraystr.split(",");
		extra = typeof extra !== 'undefined' ? extra : '';

		for ( var i = 0; i < array.length; i = i + 1 ) {
				str = str + "<" + tag + extra + ">" + array[i] + "</" + tag + ">\n";
		}

		return str;
	}

	function getPrefixCondUrl( prefixcondurl ) {

		let prefixHash = {};

		let parts = prefixcondurl.split( "," );

		for( let part of parts ) {

			let assigns = part.split( ":" );

			if ( assigns.length === 2 ) {

				let target = assigns[0];
				if ( ! prefixHash.hasOwnProperty( target ) ) {
					prefixHash[ target ] = {};
				}

				let values = assigns[1].split( "=" );

				if ( values.length === 2 ) {

					let prefix = values[1];

					let conds = values[0].split( "@" );

					if ( conds.length === 2 ) {

						let cond = conds[0];
						let val = conds[1];

						if ( ! prefixHash[target].hasOwnProperty( cond ) ) {
							 prefixHash[target][cond] = {};
						}

						prefixHash[target][cond][val] = prefix;

					}

				}

			}

		}


		return prefixHash;

	}

	function assignUrlPrefix( assign, fields ){
		let prefix = "";

		let match;
		for ( let c in assign ) {

			if ( assign.hasOwnProperty( c ) ) {

				match = c;
			}
		}

		for( let f in fields ) {

			if ( f == match ) {

				let occur = fields[f];
				if ( assign[match].hasOwnProperty( occur ) ) {
					prefix = assign[match][occur];
				}
			}
		}

		return prefix;

	}

	function generateRowTable( result, fields, tag, prefix, prefixurl, prefixcondurl, rawFields ){
		var str = "";

		rawFields = rawFields || [];

		var prefixHash = getPrefixCondUrl( prefixcondurl );

		for ( var i = 0; i < fields.length; i = i + 1 ) {
			var field = fields[i];

			var prop = "";
			var pagename = null;
			var fieldTxt = "";
			var url = "#";

			// Check reference part - OK for now
			if ( field === '*' ) {
				if ( result.hasOwnProperty("pagename") ) {
					pagename = result["pagename"];
					fieldTxt = escapeHtml( pagename );
				}
			} else if ( field === '*link' ) {
				if ( result.hasOwnProperty("pagename") ) {
					pagename = result["pagename"];
					url = mw.config.get( "wgArticlePath" ).replace('$1', pagename );
					fieldTxt = "<a href='" + url +"'>" + escapeHtml( pagename ) + "</a>";
				}
			} else if ( field === '#link' ) {
				if ( result.hasOwnProperty("pagename") ) {
					pagename = result["pagename"];
					var url = mw.config.get( "wgArticlePath" ).replace('$1', pagename );

					var pagenameHtml = pagename.replace(/\@\S+/, "");
					pagenameHtml = pagenameHtml.replace(/^\S+\:/, "");

					fieldTxt = "<a href='" + url +"'>" + escapeHtml( pagenameHtml ) + "</a>";
				}
			}
			else if ( field === '*score' ) {
				if ( result.hasOwnProperty("score") ) {
					fieldTxt = escapeHtml( result["score"] );
				}
			} else {
				var pagelink = false;
				var cleanpagelink = false;
				if ( field.startsWith("~") ) {
					field = field.replace( /^~/, "");
					pagelink = true;
				}
				if ( field.startsWith("#") ) {
					field = field.replace( /^#/, "");
					cleanpagelink = true;
				}
				if ( result.hasOwnProperty("fields") && result["fields"].hasOwnProperty(field) ) {

					var rawValue = result["fields"][field];

					// Detect here if in prefix. If so, append
					if ( prefix.hasOwnProperty( field ) ) {
						rawValue = prefix[field]+":"+rawValue;
					}

					var isRaw = rawFields.indexOf( field ) > -1;

					if ( pagelink ) {
						var linkValue = String( rawValue ).replace("#", "/");

						if ( prefixurl.hasOwnProperty( field ) ) {
							url = prefixurl[field]+linkValue;
						}  else {

							if ( prefixHash.hasOwnProperty( field ) ) {
								url = assignUrlPrefix( prefixHash[field], result["fields"] )+linkValue;
							} else {

								url = mw.config.get( "wgArticlePath" ).replace('$1', linkValue );
							}
						}

						fieldTxt = "<a href='" + url +"'>" + ( isRaw ? linkValue : escapeHtml( linkValue ) ) + "</a>";
					} else if ( cleanpagelink ) {

						var linkValue2 = String( rawValue ).replace("#", "/");

						if ( prefixurl.hasOwnProperty( field ) ) {
							url = prefixurl[field]+linkValue2;
						} else {
							if ( prefixHash.hasOwnProperty( field ) ) {
								url = assignUrlPrefix( prefixHash[field], result["fields"] )+linkValue2;
							} else {

								url = mw.config.get( "wgArticlePath" ).replace('$1', linkValue2 );
							}
						}

						var cleanLabel = linkValue2.replace(/\@\S+/, "");
						cleanLabel = cleanLabel.replace(/^\S+\:/, "");

						fieldTxt = "<a href='" + url +"'>" + ( isRaw ? cleanLabel : escapeHtml( cleanLabel ) ) + "</a>";
					} else {
						fieldTxt = isRaw ? rawValue : escapeHtml( rawValue );
					}
				}
			}
			prop = " data-prop='"+field+"' ";
			str = str + "<" + tag + prop + ">" + fieldTxt + "</" + tag + ">\n";
		}
		return str;
	}


	function generateSMWTable( tables, fields ){

		var fieldsSMW = [];

		for ( var i = 0; i < fields.length; i = i + 1 ) {
			if ( ! fields[i].startsWith("*") ) {
				fieldsSMW.push( fields[i] );
			}
		}

		$(tables).each( function( i ) {

			$(this).find("tbody > tr").each( function( r ) {

				//console.log( this );
				var row = this;
				var pagename = $(row).children("td").filter("[data-prop='*']").first().text();
				if ( ! pagename ) {
					pagename = $(row).children("td").filter("[data-prop='*link']").first().text();
				}
				// Generate ask query from this
				if ( pagename ) {

					var params = {};
					params.action = "askargs";
					params.conditions = pagename;
					params.printouts = fieldsSMW.join("|");
					params.format = "json"; // Let's put JSON

					var posting = $.get( mw.config.get( "wgScriptPath" ) + "/api.php", params );
					posting.done(function( out ) {
						if ( out && out.hasOwnProperty("query") ) {
							if ( out["query"].hasOwnProperty("results") ) {
								if ( out["query"]["results"].hasOwnProperty( pagename ) ) {
									if ( out["query"]["results"][pagename].hasOwnProperty("printouts") ) {
										var printouts = out["query"]["results"][pagename]["printouts"];
										for ( var prop in printouts ){
											if ( printouts.hasOwnProperty( prop ) ) {
												if ( prop ) {
													var tdvalue = $(row).children("td").filter("[data-prop='"+prop+"']").first();
													var finvalue = printouts[prop][0];
													if ( typeof finvalue === 'object' ) {
														finvalue = finvalue.fulltext;
														var url = mw.config.get( "wgArticlePath" ).replace('$1', finvalue );
														$(tdvalue).append( "<a href='" + url +"'>" + finvalue + "</a>" );
													} else {
														$(tdvalue).text( finvalue );
													}
												}
											}
										}
									}
								}
							}
						}
					})
					.fail( function( out ) {
						console.log("Error!");
					});
				}
			});

		});
	}

	if (typeof String.prototype.startsWith != 'function') {
		// see below for better implementation!
		String.prototype.startsWith = function (str){
		  return this.indexOf(str) === 0;
		};
	}

} )( jQuery, mediaWiki );



(function( opensearchjsfunc, $, undefined ) {


    opensearchjsfunc.test = function( text ) {
		return "test";
    };

}( window.opensearchjsfunc = window.opensearchjsfunc || {}, jQuery ));
