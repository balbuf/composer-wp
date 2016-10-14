<?php

/**
 * SVN Repository config base class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

class SVNRepositoryConfig extends RepositoryConfig {

	const repoType = 'wp-svn';
	// the repo specific config
	protected $config;
	protected static $configDefaults = [
		// base url(s)
		'url' => null,
		// paths to specific providers or to listing of providers, relative to url
		// paths ending with a slash are considered a listing and will use `svn ls` to retrieve the providers
		// otherwise, the path is taken at face value to point to a specific provider
		// the provider name that is used will only be the basename, e.g. path/basename
		'provider-paths' => [ '/' ],
		// filter provider names (especially helpful when there could be conflicting names in different dirs)
		'name-filter' => null,
		// paths to specific packages or listing of packages within the providers
		// this is relative to the provider url
		'package-paths' => [ '/' ],
		// manipulate version identifiers to make them parsable by composer
		// if the version is replaced with an empty string, it will be excluded
		'version-filter' => [ __CLASS__, 'defaultFilterVersion' ],
		// mapping of supported package types to virtual vendor(s)
		// i.e. {type} => {vendor}
		// vendors can be a single string or an array of strings
		// the requested virtual vendor of the dependency will dictate the package's type
		'package-types' => [],
		// array of package defaults that will be the basis for the package definition
		'package-defaults' => [],
		// array of values to override any fields after defaults and repo-determined are resolved
		'package-overrides' => [],
		// a function which is called on the package object after it is created and before used by the solver
		'package-filter' => null,
		// take over the search functionality with a custom handler
		'search-handler' => null,
		// load from cache
		'cache-handler' => null,
		// how long to cache the providers for (secs)
		'cache-ttl' => 0,
		// provider cache file
		'cache-file' => 'providers.json',
		// should the server cert be automatically trusted?
		'trust-cert' => false,
	];

	/**
	 * Default version filter: replace trunk with dev-trunk
	 * @param  string $version found version
	 * @return string          filtered version
	 */
	static function defaultFilterVersion( $version ) {
		return preg_replace( '/^trunk$/', 'dev-trunk', $version );
	}

}
