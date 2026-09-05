<?php
/**
 * BG Commerce Suite translation toolchain.
 *
 * Replaces the wp-cli / gettext binaries that are not available on every
 * machine this plugin is built on. Three commands:
 *
 *   php tools/i18n.php extract   Rebuild languages/bg-commerce-suite.pot
 *   php tools/i18n.php merge     Merge the POT into the bg_BG PO, keeping
 *                                every existing translation
 *   php tools/i18n.php compile   Compile the bg_BG PO into a binary MO
 *   php tools/i18n.php status    Report how many strings still lack a bg_BG
 *                                translation
 *
 * Strings are found with PHP's own tokenizer rather than a regular expression,
 * so a call spanning several lines, or one holding a parenthesis inside its
 * text, is read correctly.
 *
 * Not shipped: `tools/` is excluded from the release archive.
 *
 * @package BgCommerce3
 */

const DOMAIN   = 'bg-commerce-suite';
const POT_PATH = 'languages/bg-commerce-suite.pot';
const PO_PATH  = 'languages/bg-commerce-suite-bg_BG.po';
const MO_PATH  = 'languages/bg-commerce-suite-bg_BG.mo';

/** Gettext calls => [index of msgid, index of plural or null, index of context or null, index of domain]. */
const GETTEXT = array(
	'__'            => array( 0, null, null, 1 ),
	'_e'            => array( 0, null, null, 1 ),
	'esc_html__'    => array( 0, null, null, 1 ),
	'esc_html_e'    => array( 0, null, null, 1 ),
	'esc_attr__'    => array( 0, null, null, 1 ),
	'esc_attr_e'    => array( 0, null, null, 1 ),
	'_x'            => array( 0, null, 1, 2 ),
	'_ex'           => array( 0, null, 1, 2 ),
	'esc_html_x'    => array( 0, null, 1, 2 ),
	'esc_attr_x'    => array( 0, null, 1, 2 ),
	'_n'            => array( 0, 1, null, 3 ),
	'_nx'           => array( 0, 1, 2, 4 ),
	'_n_noop'       => array( 0, 1, null, 2 ),
);

/**
 * Every PHP file that can contain a translatable string in the shipped plugin.
 *
 * @return string[]
 */
function source_files() {
	$roots = array( 'app', 'templates' );
	$files = array();

	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = str_replace( chr( 92 ), '/', $file->getPathname() );
			}
		}
	}

	foreach ( array( 'bg-commerce-suite.php', 'uninstall.php' ) as $root_file ) {
		if ( is_file( $root_file ) ) {
			$files[] = $root_file;
		}
	}

	sort( $files );
	return $files;
}

/**
 * Pull every domain-matching gettext call out of one file.
 *
 * @param string $file Path.
 * @return array<string,array<string,mixed>> Keyed by context+msgid.
 */
function extract_file( $file ) {
	$tokens  = token_get_all( file_get_contents( $file ) );
	$count   = count( $tokens );
	$entries = array();
	$comment = '';

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_COMMENT === $token[0] && false !== stripos( $token[1], 'translators:' ) ) {
			$comment = trim( preg_replace( '{^/\*+|\*+/$|^//}', '', $token[1] ) );
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( GETTEXT[ $token[1] ] ) ) {
			continue;
		}

		// A method call ($obj->__() or Klass::__()) is not the gettext function.
		$prev = $i > 0 ? $tokens[ $i - 1 ] : null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		$spec = GETTEXT[ $token[1] ];
		$args = read_args( $tokens, $i, $count );
		if ( null === $args ) {
			continue;
		}

		$domain = isset( $args[ $spec[3] ] ) ? $args[ $spec[3] ] : null;
		if ( DOMAIN !== $domain ) {
			$comment = '';
			continue;
		}

		$msgid = isset( $args[ $spec[0] ] ) ? $args[ $spec[0] ] : null;
		if ( null === $msgid || '' === $msgid ) {
			$comment = '';
			continue;
		}

		$context = ( null !== $spec[2] && isset( $args[ $spec[2] ] ) ) ? $args[ $spec[2] ] : null;
		$plural  = ( null !== $spec[1] && isset( $args[ $spec[1] ] ) ) ? $args[ $spec[1] ] : null;
		$key     = ( null === $context ? '' : $context . "\4" ) . $msgid;

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'      => $msgid,
				'plural'     => $plural,
				'context'    => $context,
				'references' => array(),
				'comment'    => '',
			);
		}
		$entries[ $key ]['references'][] = './' . $file . ':' . $token[2];
		if ( '' !== $comment && '' === $entries[ $key ]['comment'] ) {
			$entries[ $key ]['comment'] = $comment;
		}
		if ( null !== $plural ) {
			$entries[ $key ]['plural'] = $plural;
		}
		$comment = '';
	}

	return $entries;
}

/**
 * Read the literal string arguments of the call starting at $i.
 *
 * Returns null when any argument is not a plain literal — a concatenation or a
 * variable cannot be extracted, and guessing at one would put a wrong msgid in
 * the catalogue.
 *
 * @param array $tokens Token list.
 * @param int   $i      Index of the function-name token.
 * @param int   $count  Token count.
 * @return string[]|null
 */
function read_args( array $tokens, $i, $count ) {
	$j = $i + 1;
	while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
		$j++;
	}
	if ( $j >= $count || '(' !== $tokens[ $j ] ) {
		return null;
	}

	$depth   = 0;
	$args    = array();
	$current = null;
	$dirty   = false;

	for ( ; $j < $count; $j++ ) {
		$token = $tokens[ $j ];

		if ( ! is_array( $token ) ) {
			if ( '(' === $token ) {
				$depth++;
				continue;
			}
			if ( ')' === $token ) {
				$depth--;
				if ( 0 === $depth ) {
					$args[] = $dirty ? null : $current;
					return $args;
				}
				continue;
			}
			if ( ',' === $token && 1 === $depth ) {
				$args[]  = $dirty ? null : $current;
				$current = null;
				$dirty   = false;
				continue;
			}
			if ( '.' === $token && 1 === $depth ) {
				continue; // Concatenation of literals is still resolvable.
			}
			$dirty = true;
			continue;
		}

		if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			continue;
		}

		if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$current = ( null === $current ? '' : $current ) . unquote( $token[1] );
			continue;
		}

		$dirty = true;
	}

	return null;
}

/**
 * Turn a PHP source string literal into its runtime value.
 *
 * @param string $raw Literal including quotes.
 * @return string
 */
function unquote( $raw ) {
	$quote = substr( $raw, 0, 1 );
	$body  = substr( $raw, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( chr( 92 ) . "'", chr( 92 ) . chr( 92 ) ), array( "'", chr( 92 ) ), $body );
	}

	return stripcslashes( $body );
}

/**
 * Escape a value for a PO/POT msgid or msgstr.
 *
 * @param string $value Raw value.
 * @return string
 */
function po_escape( $value ) {
	$bs    = chr( 92 );
	$value = str_replace( $bs, $bs . $bs, $value );
	$value = str_replace( '"', $bs . '"', $value );
	$value = str_replace( "\t", $bs . 't', $value );
	$value = str_replace( "\r", '', $value );
	$value = str_replace( "\n", $bs . 'n"' . "\n" . '"', $value );
	return $value;
}

/**
 * Parse a PO/POT file into entries keyed by context+msgid.
 *
 * @param string $path File path.
 * @return array{header:string,entries:array<string,array<string,mixed>>}
 */
function parse_po( $path ) {
	$entries = array();
	$header  = '';
	if ( ! is_file( $path ) ) {
		return array( 'header' => $header, 'entries' => $entries );
	}

	// NOT `\R`. Without the /u modifier PCRE applies `\R` byte by byte, and it
	// treats byte 0x85 as the NEL line separator. 0x85 is the second byte of the
	// Cyrillic „х“ (U+0445 = D1 85), so `\R` splits straight through that letter
	// and every Bulgarian translation containing it parses as empty — silently
	// dropping it on the next write. PO files only ever use CR, LF or CRLF, so
	// naming them explicitly is both correct and unambiguous.
	$lines   = preg_split( '/\r\n|\n|\r/', file_get_contents( $path ) );
	$current = null;
	$field   = null;

	$flush = static function () use ( &$current, &$entries, &$header ) {
		if ( null === $current ) {
			return;
		}
		if ( '' === $current['msgid'] && null === $current['context'] ) {
			$header = $current['msgstr'];
		} else {
			$key = ( null === $current['context'] ? '' : $current['context'] . "\4" ) . $current['msgid'];
			$entries[ $key ] = $current;
		}
		$current = null;
	};

	$blank = static function () {
		return array(
			'msgid'      => '',
			'msgstr'     => '',
			'plural'     => null,
			'msgstr_n'   => array(),
			'context'    => null,
			'references' => array(),
			'comment'    => '',
			'flags'      => '',
		);
	};

	foreach ( $lines as $line ) {
		$trim = trim( $line );

		if ( '' === $trim ) {
			$flush();
			$field = null;
			continue;
		}

		if ( 0 === strpos( $trim, '#' ) ) {
			if ( null === $current ) {
				$current = $blank();
			}
			if ( 0 === strpos( $trim, '#.' ) ) {
				$current['comment'] = trim( substr( $trim, 2 ) );
			} elseif ( 0 === strpos( $trim, '#:' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $trim, 2 ) ) ) as $ref ) {
					if ( '' !== $ref ) {
						$current['references'][] = $ref;
					}
				}
			} elseif ( 0 === strpos( $trim, '#,' ) ) {
				$current['flags'] = trim( substr( $trim, 2 ) );
			}
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr\[(\d+)\]|msgstr)\s+"(.*)"$/s', $trim, $m ) ) {
			if ( null === $current ) {
				$current = $blank();
			}
			$value = po_unescape( $m[3] );
			switch ( true ) {
				case 'msgctxt' === $m[1]:
					$current['context'] = $value;
					$field = 'context';
					break;
				case 'msgid' === $m[1]:
					$current['msgid'] = $value;
					$field = 'msgid';
					break;
				case 'msgid_plural' === $m[1]:
					$current['plural'] = $value;
					$field = 'plural';
					break;
				case 'msgstr' === $m[1]:
					$current['msgstr'] = $value;
					$field = 'msgstr';
					break;
				default:
					$idx = (int) $m[2];
					$current['msgstr_n'][ $idx ] = $value;
					$field = 'msgstr_n:' . $idx;
					break;
			}
			continue;
		}

		if ( preg_match( '/^"(.*)"$/s', $trim, $m ) && null !== $field && null !== $current ) {
			$value = po_unescape( $m[1] );
			if ( 0 === strpos( $field, 'msgstr_n:' ) ) {
				$idx = (int) substr( $field, 9 );
				$current['msgstr_n'][ $idx ] .= $value;
			} else {
				$current[ $field ] .= $value;
			}
		}
	}
	$flush();

	return array( 'header' => $header, 'entries' => $entries );
}

/**
 * @param string $value Escaped PO string body.
 * @return string
 */
function po_unescape( $value ) {
	return stripcslashes( $value );
}

/**
 * Render entries as a PO/POT body.
 *
 * @param string $header      Header msgstr.
 * @param array  $entries     Entries.
 * @param bool   $with_msgstr Emit existing translations (PO) or blanks (POT).
 * @return string
 */
function render_po( $header, array $entries, $with_msgstr ) {
	$out = "msgid \"\"\nmsgstr \"" . po_escape( $header ) . "\"\n";

	ksort( $entries, SORT_STRING );

	foreach ( $entries as $entry ) {
		$out .= "\n";
		if ( ! empty( $entry['comment'] ) ) {
			$out .= '#. ' . $entry['comment'] . "\n";
		}
		if ( ! empty( $entry['references'] ) ) {
			foreach ( array_chunk( array_unique( $entry['references'] ), 4 ) as $chunk ) {
				$out .= '#: ' . implode( ' ', $chunk ) . "\n";
			}
		}
		if ( ! empty( $entry['flags'] ) ) {
			$out .= '#, ' . $entry['flags'] . "\n";
		}
		if ( null !== $entry['context'] ) {
			$out .= 'msgctxt "' . po_escape( $entry['context'] ) . "\"\n";
		}
		$out .= 'msgid "' . po_escape( $entry['msgid'] ) . "\"\n";

		if ( ! empty( $entry['plural'] ) ) {
			$out .= 'msgid_plural "' . po_escape( $entry['plural'] ) . "\"\n";
			for ( $i = 0; $i < 2; $i++ ) {
				$value = ( $with_msgstr && isset( $entry['msgstr_n'][ $i ] ) ) ? $entry['msgstr_n'][ $i ] : '';
				$out  .= 'msgstr[' . $i . '] "' . po_escape( $value ) . "\"\n";
			}
			continue;
		}

		$value = $with_msgstr && ! empty( $entry['msgstr'] ) ? $entry['msgstr'] : '';
		$out  .= 'msgstr "' . po_escape( $value ) . "\"\n";
	}

	return $out;
}

/**
 * Compile entries into the binary MO format (little-endian).
 *
 * @param array  $entries Entries with translations.
 * @param string $header  Header msgstr.
 * @return string
 */
function compile_mo( array $entries, $header ) {
	$pairs = array( '' => $header );

	foreach ( $entries as $entry ) {
		if ( ! empty( $entry['plural'] ) ) {
			$forms = array();
			for ( $i = 0; $i < 2; $i++ ) {
				$forms[] = isset( $entry['msgstr_n'][ $i ] ) ? $entry['msgstr_n'][ $i ] : '';
			}
			if ( '' === implode( '', $forms ) ) {
				continue;
			}
			$key = ( null === $entry['context'] ? '' : $entry['context'] . "\4" ) . $entry['msgid'] . "\0" . $entry['plural'];
			$pairs[ $key ] = implode( "\0", $forms );
			continue;
		}

		if ( '' === (string) $entry['msgstr'] ) {
			continue; // Untranslated entries are simply absent; gettext falls back.
		}

		$key = ( null === $entry['context'] ? '' : $entry['context'] . "\4" ) . $entry['msgid'];
		$pairs[ $key ] = $entry['msgstr'];
	}

	ksort( $pairs, SORT_STRING );

	$count       = count( $pairs );
	$orig_table  = '';
	$trans_table = '';
	$originals   = '';
	$translations = '';

	$orig_offset  = 28 + ( $count * 16 );
	$trans_offset = $orig_offset;
	foreach ( $pairs as $key => $value ) {
		$trans_offset += strlen( $key ) + 1;
	}

	$o = $orig_offset;
	$t = $trans_offset;
	foreach ( $pairs as $key => $value ) {
		$orig_table  .= pack( 'VV', strlen( $key ), $o );
		$trans_table .= pack( 'VV', strlen( $value ), $t );
		$originals   .= $key . "\0";
		$translations .= $value . "\0";
		$o += strlen( $key ) + 1;
		$t += strlen( $value ) + 1;
	}

	return pack( 'VVVVVVV', 0x950412de, 0, $count, 28, 28 + ( $count * 8 ), 0, 0 )
		. $orig_table . $trans_table . $originals . $translations;
}

// ---------------------------------------------------------------------------

$command = isset( $argv[1] ) ? $argv[1] : 'status';

$extract_all = static function () {
	$all = array();
	foreach ( source_files() as $file ) {
		foreach ( extract_file( $file ) as $key => $entry ) {
			if ( ! isset( $all[ $key ] ) ) {
				$all[ $key ] = $entry;
				continue;
			}
			$all[ $key ]['references'] = array_merge( $all[ $key ]['references'], $entry['references'] );
			if ( '' === $all[ $key ]['comment'] ) {
				$all[ $key ]['comment'] = $entry['comment'];
			}
		}
	}
	foreach ( $all as $key => $entry ) {
		$all[ $key ]['msgstr']   = '';
		$all[ $key ]['msgstr_n'] = array();
		$all[ $key ]['flags']    = '';
	}
	return $all;
};

switch ( $command ) {
	case 'extract':
		$entries = $extract_all();
		$version = '0.0.0';
		if ( preg_match( '/Version:\s*([0-9.]+)/', file_get_contents( 'bg-commerce-suite.php' ), $m ) ) {
			$version = $m[1];
		}
		$header = "Project-Id-Version: BG Commerce Suite {$version}\n"
			. "Report-Msgid-Bugs-To: https://error.bg\n"
			. 'POT-Creation-Date: ' . gmdate( 'Y-m-d H:i' ) . "+0000\n"
			. "PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"
			. "Last-Translator: FULL NAME <EMAIL@ADDRESS>\n"
			. "Language-Team: LANGUAGE <LL@li.org>\n"
			. "MIME-Version: 1.0\n"
			. "Content-Type: text/plain; charset=utf-8\n"
			. "Content-Transfer-Encoding: 8bit\n"
			. "Plural-Forms: nplurals=2; plural=(n != 1);\n"
			. "Generated-By: tools/i18n.php\n";
		file_put_contents( POT_PATH, render_po( $header, $entries, false ) );
		printf( "extract: %d strings -> %s\n", count( $entries ), POT_PATH );
		break;

	case 'merge':
		$source = $extract_all();
		$po     = parse_po( PO_PATH );
		$kept   = 0;
		$added  = 0;

		foreach ( $source as $key => $entry ) {
			if ( isset( $po['entries'][ $key ] ) ) {
				$old = $po['entries'][ $key ];
				$source[ $key ]['msgstr']   = $old['msgstr'];
				$source[ $key ]['msgstr_n'] = $old['msgstr_n'];
				$source[ $key ]['flags']    = $old['flags'];
				if ( '' !== (string) $old['msgstr'] || ! empty( $old['msgstr_n'] ) ) {
					$kept++;
				}
			} else {
				$added++;
			}
		}

		$header = $po['header'];
		if ( '' === $header ) {
			$header = "Project-Id-Version: BG Commerce Suite\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nLanguage: bg_BG\nPlural-Forms: nplurals=2; plural=(n != 1);\n";
		}
		if ( false === strpos( $header, 'Plural-Forms' ) ) {
			$header .= "Plural-Forms: nplurals=2; plural=(n != 1);\n";
		}

		$obsolete = count( $po['entries'] ) - $kept;
		file_put_contents( PO_PATH, render_po( $header, $source, true ) );
		printf(
			"merge: %d strings in source, %d translations kept, %d new, %d stale entries dropped -> %s\n",
			count( $source ),
			$kept,
			$added,
			max( 0, $obsolete ),
			PO_PATH
		);
		break;

	case 'selftest':
		// Parse the catalogue, render it, parse it again, and require the two
		// readings to be identical. Any lossy or corrupting step shows up here as
		// a concrete list of damaged strings rather than as translations quietly
		// vanishing from a shipped MO. Run this before merge and after apply.
		$first = parse_po( PO_PATH );
		$tmp   = tempnam( sys_get_temp_dir(), 'bgcs-po-' );
		file_put_contents( $tmp, render_po( $first['header'], $first['entries'], true ) );
		$second = parse_po( $tmp );
		unlink( $tmp );

		$damaged = array();
		foreach ( $first['entries'] as $key => $entry ) {
			if ( ! isset( $second['entries'][ $key ] ) ) {
				$damaged[] = 'LOST: ' . $entry['msgid'];
				continue;
			}
			if ( $entry['msgstr'] !== $second['entries'][ $key ]['msgstr'] ) {
				$damaged[] = 'CHANGED: ' . $entry['msgid'];
			}
		}

		$raw    = file_get_contents( PO_PATH );
		$actual = substr_count( $raw, "\n" );
		$viaR   = count( preg_split( '/\R/', $raw ) ) - 1;

		printf( "selftest: %d entries, %d damaged by round-trip\n", count( $first['entries'] ), count( $damaged ) );
		if ( $actual !== $viaR ) {
			printf(
				"note: %d byte sequences in this catalogue would be mis-split by a \\R regex without /u (Cyrillic „х“ is D1 85, and 0x85 is NEL). The parser does not use \\R.\n",
				$viaR - $actual
			);
		}
		foreach ( array_slice( $damaged, 0, 20 ) as $line ) {
			echo '  ' . str_replace( "\n", ' ', $line ) . "\n";
		}
		if ( $damaged ) {
			exit( 1 );
		}
		break;

	case 'apply':
		// Takes a PHP file returning msgid => msgstr. Never overwrites an existing
		// translation, and refuses any translation whose printf placeholders do not
		// match the original — a dropped or renamed placeholder is a runtime bug,
		// not a wording choice.
		$file = isset( $argv[2] ) ? $argv[2] : '';
		if ( ! is_file( $file ) ) {
			fwrite( STDERR, "apply: translations file not found: $file\n" );
			exit( 1 );
		}

		$map     = require $file;
		$po      = parse_po( PO_PATH );
		$applied = 0;
		$unknown = array();
		$badfmt  = array();

		foreach ( $map as $msgid => $msgstr ) {
			if ( ! isset( $po['entries'][ $msgid ] ) ) {
				$unknown[] = $msgid;
				continue;
			}
			if ( '' !== (string) $po['entries'][ $msgid ]['msgstr'] ) {
				continue;
			}

			preg_match_all( '/%(?:\d+\$)?[sd]/', $msgid, $a );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $msgstr, $b );
			sort( $a[0] );
			sort( $b[0] );
			if ( $a[0] !== $b[0] ) {
				$badfmt[] = $msgid;
				continue;
			}

			$po['entries'][ $msgid ]['msgstr'] = $msgstr;
			$applied++;
		}

		file_put_contents( PO_PATH, render_po( $po['header'], $po['entries'], true ) );

		printf( "apply: %d translations written\n", $applied );
		foreach ( $unknown as $u ) {
			echo '  NOT IN CATALOGUE: ' . str_replace( "\n", ' ', $u ) . "\n";
		}
		foreach ( $badfmt as $b ) {
			echo '  PLACEHOLDER MISMATCH: ' . str_replace( "\n", ' ', $b ) . "\n";
		}
		if ( $unknown || $badfmt ) {
			exit( 1 );
		}
		break;

	case 'compile':
		$po = parse_po( PO_PATH );
		file_put_contents( MO_PATH, compile_mo( $po['entries'], $po['header'] ) );
		$translated = 0;
		foreach ( $po['entries'] as $entry ) {
			if ( '' !== (string) $entry['msgstr'] || ! empty( array_filter( $entry['msgstr_n'] ) ) ) {
				$translated++;
			}
		}
		printf( "compile: %d translated strings -> %s (%d bytes)\n", $translated, MO_PATH, filesize( MO_PATH ) );
		break;

	case 'status':
	default:
		$source = $extract_all();
		$po     = parse_po( PO_PATH );
		$missing = array();
		foreach ( $source as $key => $entry ) {
			$has = isset( $po['entries'][ $key ] )
				&& ( '' !== (string) $po['entries'][ $key ]['msgstr'] || ! empty( array_filter( $po['entries'][ $key ]['msgstr_n'] ) ) );
			if ( ! $has ) {
				$missing[] = $entry['msgid'];
			}
		}
		printf( "source strings : %d\n", count( $source ) );
		printf( "translated     : %d\n", count( $source ) - count( $missing ) );
		printf( "missing bg_BG  : %d\n", count( $missing ) );
		if ( in_array( '--list', $argv, true ) ) {
			foreach ( $missing as $msgid ) {
				echo '  ' . str_replace( "\n", ' ', $msgid ) . "\n";
			}
		}
		break;
}
