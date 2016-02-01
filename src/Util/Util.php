<?php

/**
 * General utilities.
 */

namespace BalBuf\ComposerWP\Util;

use Composer\Semver\VersionParser;

class Util {

	static $versionParser;

	/**
	 * Determine whether a command exists.
	 * @param  string $cmd command name
	 * @return bool       whether it exists
	 * @todo  see if this works on windows
	 */
	static function cmdExists( $cmd ) {
		$which = strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ? 'where' : 'which';
		exec( "$which $cmd", $output, $return_var );
		return $return_var === 0;
	}

	/**
	 * Try to fix a version so it will satisfy composer.
	 * @param  string $version original version
	 * @param  string $fallback fallback version if it cannot be fixed
	 * @return string          version suitable for composer
	 */
	static function fixVersion( $version, $fallback = '' ) {
		if ( !isset( self::$versionParser ) ) {
			self::$versionParser = new VersionParser();
		}
		// first, see if it passes
		try {
			self::$versionParser->normalize( $version );
			// it worked!
			return $version;
		} catch ( \UnexpectedValueException $e ) { }
		// strip anything not alphanumeric or: . - _
		$version = preg_replace( '/[^.-_a-z0-9]/i', '', $version );
		// strip all letters that do not comprise a valid modifier
		$version = preg_replace( '/(?!stable|beta|b|RC|alpha|a|patch|pl|p|dev|master|trunk|default\b)\b[a-z]+/i', '', $version );
		// try again!
		try {
			self::$versionParser->normalize( $version );
			// it worked!
			return $version;
		} catch ( \UnexpectedValueException $e ) {
			// return fallback
			return $fallback;
		}
	}

	/**
	 * Roughly how wordpress converts titles to slugs.
	 * @see    sanitize_title_with_dashes()
	 * @param  string $name string to sanitize
	 * @return string       sanitized strinh
	 */
	static function slugify( $name ) {
		$name = strtolower( $name );
		$name = preg_replace( '/[\s.]+/', '-', $name );
		$name = preg_replace( '/[^a-z0-9-_]/', '', $name );
		return trim( $name, '-_' );
	}

	/**
	 * Call the passed function, if valid, using the provided args.
	 * @param  callable $callable filter function
	 * @param  mixed    $value   the initial value
	 * @param  mixed    ....  any additional params to pass
	 * @return mixed            the final value
	 * @todo whitelist for special callables?
	 */
	static function callFilter( $callable, $value ) {
		$args = array_slice( func_get_args(), 1 );
		// special kind of callable - an array of two elements
		// first element is any kind of valid callable,
		// second element is an array of args to pass
		if ( isset( $callable[1] ) && is_array( $callable[1] ) ) {
			$userArgs = $callable[1];
			$callable = $callable[0];
			// replace arg tokens, i.e. $arg[0], with the corresponding filter arg
			$args = array_map( function( $el ) use ( $args ) {
				if ( is_string( $el ) && preg_match( '/^\\$arg\\[(\d+)\\]$/', $el, $matches ) ) {
					if ( isset( $args[ $matches[1] ] ) ) {
						return $args[ $matches[1] ];
					}
				}
				return $el;
			} , $userArgs );
		}
		if ( is_callable( $callable ) ) {
			$value = call_user_func_array( $callable, $args );
		}
		return $value;
	}

}
