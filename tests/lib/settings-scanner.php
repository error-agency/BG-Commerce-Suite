<?php
/**
 * Static scanner behind the three settings guards (TASK-K1).
 *
 * The audit found four defects of this class by hand — three dead Econt
 * settings (BGCS-AUDIT-002), a ghost key that was read but never declared
 * (BGCS-AUDIT-004) and three drifted defaults (BGCS-AUDIT-003/-005/-016) — and
 * observed that all four would have been caught by the same two passes over the
 * source. This is those passes.
 *
 * Two things a naive grep gets wrong, and both hid a real finding:
 *
 *   1. Field definitions are not always array literals. `Pricing::fields_for()`
 *      builds them by ASSIGNMENT (`$fields['default_weight'] = array(...)`) and
 *      Econt builds some with a ternary — that is how `default_weight` escaped
 *      the audit's own first-pass detector.
 *   2. A runtime default is not always a quoted string. Econt passed the class
 *      constant `Weight::MIN_KG`, which is why the 0.01-vs-1 kg mismatch was
 *      invisible to a literal-only comparison.
 *
 * Two key sets are produced, deliberately different:
 *
 *   {@see bgcs_declared_field_keys()} is PRECISE — only the top-level keys of a
 *   field map, so "is this key a real setting?" can be answered. Used by the
 *   coverage guard, where a false positive would fail the build.
 *
 *   {@see bgcs_declared_keys()} is PERMISSIVE — every string key appearing in a
 *   declaring function. Used by the ghost-key guard, where over-inclusion only
 *   costs sensitivity and under-inclusion would fail the build wrongly.
 *
 * @package BgCommerce3
 */

/** Files that declare settings fields, and the option group they declare for. */
function bgcs_declaring_files() {
	return array(
		'app/Modules/Shipping/Speedy/Speedy.php'    => 'speedy',
		'app/Modules/Shipping/Econt/Econt.php'      => 'econt',
		'app/Modules/Shipping/BoxNow/BoxNow.php'    => 'boxnow',
		'app/Modules/Shipping/Pigeon/Pigeon.php'    => 'pigeon',
		'app/Shipping/Pricing.php'                  => '*',
		'app/Shipping/Tracking_Sync.php'            => '*',
		'app/Shipping/Cod_Payout_Sync_Settings.php' => '*',
		'app/Shipping/Tracking_Status_Policy.php'   => '*',
	);
}

/** Functions whose bodies compose the field set. */
function bgcs_declaring_functions() {
	return array( 'settings_fields', 'fields_for' );
}

/** Courier module directories, keyed by option group. */
function bgcs_module_dirs() {
	return array(
		'speedy' => 'app/Modules/Shipping/Speedy',
		'econt'  => 'app/Modules/Shipping/Econt',
		'boxnow' => 'app/Modules/Shipping/BoxNow',
		'pigeon' => 'app/Modules/Shipping/Pigeon',
	);
}

/* -------------------------------------------------------------------------
 * Precise extraction: real field keys and their attributes
 * ---------------------------------------------------------------------- */

/**
 * Every declared setting, with the attributes the guards need.
 *
 * @param string $root Repository root.
 * @return array<string,array<string,array{type:string,default:string,has_default:bool,file:string}>>
 *         group => key => attributes
 */
function bgcs_declared_fields( $root ) {
	$out = array();

	foreach ( bgcs_declaring_files() as $rel => $group ) {
		$path = $root . '/' . $rel;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		foreach ( bgcs_scan_fields( $path ) as $key => $attributes ) {
			$attributes['file'] = $rel;
			if ( ! isset( $out[ $group ][ $key ] ) ) {
				$out[ $group ][ $key ] = $attributes;
			}
		}
	}

	return $out;
}

/**
 * Just the key names, per group.
 *
 * @param string $root Repository root.
 * @return array<string,string[]>
 */
function bgcs_declared_field_keys( $root ) {
	$out = array();
	foreach ( bgcs_declared_fields( $root ) as $group => $fields ) {
		$out[ $group ] = array_keys( $fields );
	}
	return $out;
}

/**
 * Walk a file's declaring functions, tracking whether each open array is a map
 * OF fields or the definition OF one field. Without that distinction `'type'`
 * and `'options'` look exactly like setting names.
 *
 * @param string $path File.
 * @return array<string,array{type:string,default:string,has_default:bool}>
 */
function bgcs_scan_fields( $path ) {
	$tokens = token_get_all( file_get_contents( $path ) );
	$out    = array();

	foreach ( bgcs_declaring_bodies( $tokens ) as $body ) {
		$out = array_merge( $out, bgcs_scan_field_map( $body ) );
	}

	return $out;
}

/**
 * @param array<int,mixed> $tokens Whole-file tokens.
 * @return array<int,array<int,mixed>> One token slice per declaring function body.
 */
function bgcs_declaring_bodies( array $tokens ) {
	$bodies = array();
	$names  = bgcs_declaring_functions();
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}

		$name = null;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$name = $tokens[ $j ][1];
				break;
			}
			if ( '(' === $tokens[ $j ] ) {
				break;
			}
		}
		if ( null === $name || ! in_array( $name, $names, true ) ) {
			continue;
		}

		$depth = 0;
		$start = null;
		for ( $j = $i; $j < $count; $j++ ) {
			if ( '{' === $tokens[ $j ] ) {
				$depth++;
				if ( null === $start ) {
					$start = $j;
				}
			} elseif ( '}' === $tokens[ $j ] ) {
				$depth--;
				if ( 0 === $depth && null !== $start ) {
					$bodies[] = array_slice( $tokens, $start, $j - $start + 1 );
					break;
				}
			}
		}
	}

	return $bodies;
}

/**
 * @param array<int,mixed> $tokens Token slice for one declaring function body.
 * @return array<string,array{type:string,default:string,has_default:bool}>
 */
function bgcs_scan_field_map( array $tokens ) {
	$out   = array();
	$count = count( $tokens );

	// Stack of open brackets: 'map' (keys are field names), 'def' (keys are
	// field attributes), 'other' (anything nested deeper), 'call' (not an array).
	$stack       = array();
	$pending_key = null;   // field key awaiting its definition array
	$def_key     = null;   // field whose definition we are currently inside

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		// ---- bracket bookkeeping ------------------------------------------
		if ( '(' === $token || '[' === $token ) {
			$is_array = bgcs_opens_array( $tokens, $i );
			if ( ! $is_array ) {
				$stack[] = 'call';
				continue;
			}

			$enclosing = bgcs_innermost_array( $stack );
			if ( null !== $pending_key ) {
				// `'key' => array( ... )` inside a field map, or
				// `$fields['key'] = array( ... )` at statement level.
				$stack[]     = 'def';
				$def_key     = $pending_key;
				$pending_key = null;
			} elseif ( null === $enclosing && bgcs_is_field_map( $tokens, $i ) ) {
				$stack[] = 'map';
			} else {
				// A reusable field TEMPLATE (`$service_field = array( 'type' => …
				// )`) also sits at statement level, and its keys are attributes,
				// not settings. Treating those as fields is how `type`, `label`
				// and `options` end up looking like eight extra settings.
				$stack[] = 'other';
			}
			continue;
		}

		if ( ')' === $token || ']' === $token ) {
			$closed = array_pop( $stack );
			if ( 'def' === $closed ) {
				$def_key = null;
			}
			continue;
		}

		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			if ( ',' === $token || ';' === $token ) {
				$pending_key = null;
			}
			continue;
		}

		$name    = trim( $token[1], "'\"" );
		$context = bgcs_innermost_array( $stack );

		// ---- `'key' => ...` -------------------------------------------------
		$next = bgcs_next_significant( $tokens, $i );
		if ( null !== $next && is_array( $tokens[ $next ] ) && T_DOUBLE_ARROW === $tokens[ $next ][0] ) {
			if ( 'map' === $context ) {
				$out[ $name ] = isset( $out[ $name ] ) ? $out[ $name ] : array( 'type' => '', 'default' => '', 'has_default' => false );
				$pending_key  = $name;
			} elseif ( 'def' === $context && null !== $def_key ) {
				$value = bgcs_next_significant( $tokens, $next );
				$raw   = ( null !== $value ) ? ( is_array( $tokens[ $value ] ) ? $tokens[ $value ][1] : (string) $tokens[ $value ] ) : '';
				if ( 'type' === $name ) {
					$out[ $def_key ]['type'] = trim( $raw, "'\"" );
				} elseif ( 'default' === $name ) {
					$out[ $def_key ]['default']     = trim( $raw, "'\"" );
					$out[ $def_key ]['has_default'] = true;
				}
			}
			continue;
		}

		// ---- `$fields['key'] = array( ... )` --------------------------------
		if ( null !== $next && ']' === $tokens[ $next ] && null === $context ) {
			$after = bgcs_next_significant( $tokens, $next );
			if ( null !== $after && '=' === $tokens[ $after ] ) {
				$out[ $name ] = isset( $out[ $name ] ) ? $out[ $name ] : array( 'type' => '', 'default' => '', 'has_default' => false );
				$pending_key  = $name;
			}
		}
	}

	return $out;
}

/**
 * Is the statement-level array at $index the field map itself, rather than a
 * reusable field template that happens to sit at the same level?
 *
 * The map is what a declaring function returns, or the `$fields` accumulator it
 * returns at the end.
 *
 * @param array<int,mixed> $tokens Tokens.
 * @param int              $index  Position of the array-opening bracket.
 * @return bool
 */
function bgcs_is_field_map( array $tokens, $index ) {
	$seen = 0;
	for ( $i = $index - 1; $i >= 0 && $seen < 4; $i-- ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$seen++;

		if ( is_array( $token ) && T_ARRAY === $token[0] ) {
			continue;
		}
		if ( is_array( $token ) && T_RETURN === $token[0] ) {
			return true;
		}
		// `array_merge( $fields, array( … ) )` and friends.
		if ( is_array( $token ) && T_STRING === $token[0] && 'array_merge' === strtolower( $token[1] ) ) {
			continue;
		}
		if ( '(' === $token || ',' === $token ) {
			continue;
		}
		if ( is_array( $token ) && T_VARIABLE === $token[0] ) {
			return 'fields' === strtolower( ltrim( $token[1], '$' ) );
		}
		if ( '=' === $token ) {
			continue;
		}
		return false;
	}

	return false;
}

/**
 * Does the bracket at $index open an array literal, rather than a call or an
 * index access?
 *
 * @param array<int,mixed> $tokens Tokens.
 * @param int              $index  Position of `(` or `[`.
 * @return bool
 */
function bgcs_opens_array( array $tokens, $index ) {
	$previous = null;
	for ( $i = $index - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$previous = $tokens[ $i ];
		break;
	}

	if ( '(' === $tokens[ $index ] ) {
		return is_array( $previous ) && T_ARRAY === $previous[0];
	}

	// `[` is an index when it follows something subscriptable.
	if ( ']' === $previous || ')' === $previous ) {
		return false;
	}
	if ( is_array( $previous ) && in_array( $previous[0], array( T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
		return false;
	}

	return true;
}

/**
 * @param string[] $stack Bracket stack.
 * @return string|null Innermost array context, ignoring call parentheses.
 */
function bgcs_innermost_array( array $stack ) {
	for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
		if ( 'call' !== $stack[ $i ] ) {
			return $stack[ $i ];
		}
	}
	return null;
}

/**
 * @param array<int,mixed> $tokens Tokens.
 * @param int              $index  Current position.
 * @return int|null Index of the next non-whitespace token.
 */
function bgcs_next_significant( array $tokens, $index ) {
	$count = count( $tokens );
	for ( $i = $index + 1; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $i;
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Runtime readers
 * ---------------------------------------------------------------------- */

/**
 * Every settings key read at runtime, in any of the forms this codebase uses.
 *
 * A key can be reached directly, through `Label_Builder`'s private `option()`
 * wrapper, or as the third argument of the `wbx_bool()` / `wbx_value()`
 * order-override resolvers — a coverage guard blind to the last of those would
 * report most of Econt's shipment services as dead.
 *
 * @param string $root Repository root.
 * @return array<string,string[]> key => sites that read it
 */
function bgcs_setting_readers( $root ) {
	$patterns = array(
		"/(?:bgcs3_get_option|Options::get|Module_Settings::get)\(\s*[^,]+?,\s*'([a-z0-9_]+)'/i",
		"/->option\(\s*'([a-z0-9_]+)'/i",
		"/wbx_(?:bool|value)\(\s*[^,]+?,\s*(?:'[a-z0-9_]*'|\\\$[a-z_]+)\s*,\s*'([a-z0-9_]+)'/i",
	);

	$found = array();
	$rii   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );

	foreach ( $rii as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );

		foreach ( file( $file->getPathname() ) as $index => $line ) {
			foreach ( $patterns as $pattern ) {
				if ( ! preg_match_all( $pattern, $line, $matches ) ) {
					continue;
				}
				foreach ( $matches[1] as $key ) {
					$found[ $key ][] = $rel . ':' . ( $index + 1 );
				}
			}
		}
	}

	return $found;
}

/**
 * Keys referenced through a constant rather than a literal, e.g.
 * `bgcs3_get_option( $id, Pricing::MODE_KEY )`. Resolved from the constant
 * definitions so a legitimately-read key is not reported as dead.
 *
 * @param string $root Repository root.
 * @return string[]
 */
function bgcs_constant_keys( $root ) {
	$keys = array();
	$rii  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );

	foreach ( $rii as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$src = file_get_contents( $file->getPathname() );
		if ( preg_match_all( "/const\s+[A-Z0-9_]+\s*=\s*'([a-z0-9_]+)'\s*;/", $src, $m ) ) {
			$keys = array_merge( $keys, $m[1] );
		}
	}

	return array_values( array_unique( $keys ) );
}

/**
 * Keys a module writes into its own option group. A module may legitimately
 * store state that has no settings field — a cached account profile, a pending
 * courier request, a repeater rendered by its own UI. What it may not do is
 * READ a key that neither it nor `settings_fields()` ever establishes; that is
 * a ghost key, and it returns its default forever (BGCS-AUDIT-004).
 *
 * @param string $root Repository root.
 * @param string $dir  Module directory, relative to the root.
 * @return string[]
 */
function bgcs_keys_written_by( $root, $dir ) {
	$keys = array();
	$rii  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );

	foreach ( $rii as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$src = file_get_contents( $file->getPathname() );
		if ( preg_match_all( "/(?:bgcs3_set_option|Options::set)\(\s*[^,]+,\s*'([a-z0-9_]+)'/i", $src, $m ) ) {
			$keys = array_merge( $keys, $m[1] );
		}
	}

	return array_values( array_unique( $keys ) );
}

/* -------------------------------------------------------------------------
 * Permissive extraction, for the ghost-key guard
 * ---------------------------------------------------------------------- */

/**
 * Every string key appearing anywhere in a declaring function — field names and
 * the attribute/option names nested inside them alike.
 *
 * Deliberately over-inclusive: this feeds the guard that fails when a key is
 * READ but declared nowhere, so under-inclusion would break the build over a
 * field the scanner merely failed to parse, while over-inclusion only means a
 * key that coincides with an attribute name goes unchallenged.
 *
 * @param string $root Repository root.
 * @return array<string,string[]> group => keys
 */
function bgcs_declared_keys( $root ) {
	$out = array();

	foreach ( bgcs_declaring_files() as $rel => $group ) {
		$path = $root . '/' . $rel;
		if ( ! is_readable( $path ) ) {
			continue;
		}

		$keys = array();
		foreach ( bgcs_declaring_bodies( token_get_all( file_get_contents( $path ) ) ) as $body ) {
			foreach ( $body as $index => $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					continue;
				}
				$next = bgcs_next_significant( $body, $index );
				if ( null !== $next && is_array( $body[ $next ] ) && T_DOUBLE_ARROW === $body[ $next ][0] ) {
					$keys[] = trim( $token[1], "'\"" );
				} elseif ( null !== $next && ']' === $body[ $next ] ) {
					$keys[] = trim( $token[1], "'\"" );
				}
			}
		}

		$out[ $group ] = array_values( array_unique( array_merge( isset( $out[ $group ] ) ? $out[ $group ] : array(), $keys ) ) );
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * Default consistency
 * ---------------------------------------------------------------------- */

/**
 * Every declared default, keyed by setting key.
 *
 * @param string $root Repository root.
 * @return array<string,string[]>
 */
function bgcs_declared_defaults( $root ) {
	$out = array();

	foreach ( bgcs_declared_fields( $root ) as $fields ) {
		foreach ( $fields as $key => $attributes ) {
			if ( $attributes['has_default'] ) {
				$out[ $key ][] = $attributes['default'];
			}
		}
	}

	foreach ( $out as $key => $values ) {
		$out[ $key ] = array_values( array_unique( $values ) );
	}

	return $out;
}

/**
 * Reads that still pass a default the field already declares — a second copy of
 * a value that has a home (BGCS-AUDIT-003/-005/-016).
 *
 * @param string                 $root     Repository root.
 * @param array<string,string[]> $declared Declared defaults.
 * @return string[]
 */
function bgcs_reads_repeating_a_declared_default( $root, array $declared ) {
	$offenders = array();

	// These run while the field set is being composed, so `Module_Settings`'
	// re-entrancy guard deliberately makes the declared default unavailable to
	// them; they must keep a literal of their own.
	$build_time = array(
		'app/Modules/Shipping/BoxNow/BoxNow.php:716',
		'app/Modules/Shipping/BoxNow/BoxNow.php:770',
		'app/Modules/Shipping/Econt/Econt.php:506',
	);

	$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );
	foreach ( $rii as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );

		foreach ( file( $file->getPathname() ) as $index => $line ) {
			$site = $rel . ':' . ( $index + 1 );
			if ( in_array( $site, $build_time, true ) ) {
				continue;
			}
			if ( ! preg_match_all( "/bgcs3_get_option\(\s*([^,]+?),\s*'([a-z0-9_]+)'\s*,\s*(.+?)\s*\)\s*[,;)]/i", $line, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$key = $match[2];
				if ( ! isset( $declared[ $key ] ) ) {
					continue;
				}
				$offenders[] = $site . '  ' . $key . '  passes ' . trim( $match[3] )
					. '  (declared: ' . implode( ' | ', $declared[ $key ] ) . ')';
			}
		}
	}

	return $offenders;
}

/**
 * Proof that the reader pattern matches a constant default, not only a quoted
 * literal — the blind spot that hid BGCS-AUDIT-016.
 *
 * @return bool
 */
function bgcs_scanner_matches_constant_defaults() {
	$line    = "\$w = bgcs3_get_option( self::ID, 'default_weight', Weight::MIN_KG );";
	$matched = preg_match( "/bgcs3_get_option\(\s*([^,]+?),\s*'([a-z0-9_]+)'\s*,\s*(.+?)\s*\)\s*[,;)]/i", $line, $m );
	return 1 === $matched && 'Weight::MIN_KG' === trim( $m[3] );
}
