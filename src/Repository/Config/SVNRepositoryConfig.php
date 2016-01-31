<?php

/**
 * SVN Repository config base class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

abstract class SVNRepositoryConfig implements RepositoryConfigInterface {

	// the repo specific config
	protected $config = array();
	private static $configDefaults = array(
		// base url
		'url' => null,
		// paths to specific providers or to listing of providers, relative to url
		// paths ending with a slash are considered a listing and will use `svn ls` to retrieve the providers
		// otherwise, the path is taken at face value to point to a specific provider
		// the provider name that is used will only be the basename, e.g. path/basename
		'provider-paths' => array( '/' ),
		// filter provider names (especially helpful when there could be conflicting names in different dirs)
		'provider-filter' => null,
		// paths to specific packages or listing of packages within the providers
		// this is relative to the provider url
		'package-paths' => array( '/' ),
		// manipulate version identifiers to make them parsable by composer
		// if the version is replaced with an empty string, it will be excluded
		'version-filter' => array( __CLASS__, 'filterVersion' ),
		// mapping of supported package types to virtual vendor(s)
		// i.e. {type} => {vendor}
		// vendors can be a single string or an array of strings
		// the requested virtual vendor of the dependency will dictate the package's type
		'types' => array(),
		// array of package defaults that will be the basis for the package definition
		'package-defaults' => array(),
		// array of values to override any fields after defaults and repo-determined are resolved
		'package-overrides' => array(),
		// a function which is called on the package object after it is created and before used by the solver
		'package-filter' => null,
	);

	/**
	 * Default version filter: replace trunk with dev-trunk
	 * @param  string $version found version
	 * @return string          filtered version
	 */
	static function filterVersion( $version ) {
		return preg_replace( '/^trunk$/', 'dev-trunk', $version );
	}

	function __construct() {
		// replace these defaults into the config values of the instantiated child class
		$this->config = array_replace( self::$configDefaults, $this->config );
	}

	function getConfig() {
		return $this->config;
	}

	function set( $key, $value ) {
		$this->config[ $key ] = $value;
	}

	function get( $key ) {
		if ( isset( $this->config[ $key ] ) ) {
			return $this->config[ $key ];
		}
	}

	function getRepositoryType() {
		return 'wp-svn';
	}

	function getConfigDefaults() {
		return self::$configDefaults;
	}

}
