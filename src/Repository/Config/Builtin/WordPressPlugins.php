<?php

/**
 * Repository definition for the WordPress plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use BalBuf\ComposerWP\Util\Util;
use Composer\Cache;
use RuntimeException;

class WordPressPlugins extends SVNRepositoryConfig {

	const apiUrl = 'https://api.wordpress.org/plugins/info/1.0/';
	const newestNum = 100; // how many of the newest plugins to pull for comparison

	protected $pluginInfo = [];

	function __construct() {
		// set here so that we have a reference to $this
		$this->config = [
			'url' => 'https://plugins.svn.wordpress.org/',
			'package-paths' => [ '/tags/', '/trunk' ],
			'package-types' => [ 'wordpress-plugin' => 'wordpress-plugin', 'wordpress-muplugin' => 'wordpress-muplugin' ],
			'package-filter' => [ $this, 'filterPackage' ],
			'search-handler' => [ $this, 'search' ],
			'cache-handler' => [ $this, 'cache' ],
			// store the cache for however long the default is - it will likely get invalidated before then
			'cache-ttl' => 'config',
		];
		parent::__construct();
	}

	/**
	 * Filter the package to add dist and other meta information.
	 */
	function filterPackage( CompletePackage $package ) {
		list( $vendor, $shortName ) = explode( '/', $package->getName() );
		// try to get the plugin info - may return an array or null/false
		try {
			$info = $this->getPluginInfo( $shortName );
		} catch ( RuntimeException $e ) {
			if ( $io->isVerbose() ) {
				$io->writeError( $e->getMessage() );
			}
			// this allows us to try again for this plugin
			return;
		}
		if ( $info ) {
			// set the dist info
			$package->setDistType( 'zip' );
			// strip out "tags", "trunk", slashes, and spaces
			$version = preg_replace( '/tags|trunk|[\\/ ]/', '', $package->getSourceReference() );
			// if there is a version identifier, prepend with a period
			$version = $version ? ".$version" : '';
			// set the dist url
			$package->setDistUrl( 'https://downloads.wordpress.org/plugin/' . urlencode( $shortName . $version ) . '.zip' );

			// set some additional meta info
			// this is inconsequential to the solver, but it gets stored in composer.lock
			// and appears when running `composer show vendor/package`
			if ( isset( $info['short_description'] ) ) {
				$package->setDescription( $info['short_description'] );
			}
			if ( !empty( $info['contributors'] ) ) {
				$authors = [];
				foreach ( $info['contributors'] as $name => $homepage ) {
					$authors[] = [
						'name' => $name,
						'homepage' => $homepage,
					];
				}
				$package->setAuthors( $authors );
			}
			if ( !empty( $info['tags'] ) ) {
				$package->setKeywords( $info['tags'] );
			}
			// URL-ready slug
			$pluginSlug = urlencode( $shortName );
			$package->setSupport( [
				'forum' => "https://wordpress.org/support/plugin/$pluginSlug/",
				'source' => "http://plugins.trac.wordpress.org/browser/$pluginSlug/",
				'docs' => "https://wordpress.org/plugins/$pluginSlug/",
			] );
			$package->setHomepage( "https://wordpress.org/plugins/$pluginSlug/" );
		} else {
			// null means the package is no longer active
			$package->setAbandoned( true );
		}
	}

	/**
	 * Ask the API about a plugin.
	 * @param  string $plugin plugin slug
	 * @return mixed         array of plugin data or null if no data / false if retrieval error
	 */
	function getPluginInfo( $plugin ) {
		if ( !array_key_exists( $plugin, $this->pluginInfo ) ) {
			// ask the API about this plugin
			$url = self::apiUrl . urlencode( $plugin ) . '.json';
			if ( $this->io->isVerbose() ) {
				$this->io->write( "Requesting more information about $plugin plugin: $url" );
			}
			$response = file_get_contents( $url );
			// was the retrieval successful?
			if ( $response === false ) {
				throw new RuntimeException( "Could not retrieve $url" );
			} else {
				$data = json_decode( $response, true );
				if ( json_last_error() !== \JSON_ERROR_NONE ) {
					throw new RuntimeException( 'JSON error occured: ' . json_last_error_msg() );
				}
				if ( $this->io->isDebug() ) {
					$this->io->write( "Plugin data: \n" . print_r( $data, true ) );
				}
				$this->pluginInfo[ $plugin ] = $data;
			}
		}
		return $this->pluginInfo[ $plugin ];
	}

	/**
	 * Query the plugins API for information.
	 * @see http://codex.wordpress.org/WordPress.org_API#Plugins
	 * @param  string $action request action
	 * @param  array $request request data
	 * @return mixed          response
	 */
	function queryAPI( $action, array $request ) {
		// data to send to the api
		$data = [ 'action' => $action, 'request' => serialize( (object) $request ) ];
		// create stream context for file_get_contents
		$ctx = stream_context_create( [
			'http' => [
				'method' => 'POST',
				'header' => 'Content-type: application/x-www-form-urlencoded',
				'content' => http_build_query( $data ),
			]
		] );
		$url = self::apiUrl;
		if ( $this->io->isVerbose() ) {
			$this->io->write( "Fetching $url with " . print_r( $data, true ) );
		}
		// query the api
		$response = file_get_contents( $url, false, $ctx );
		if ( $response === false ) {
			throw new RuntimeException( "Could not retrieve $url" );
		}
		return unserialize( $response );
	}

	/**
	 * Query the WP plugins api for results.
	 * @param  string $query search query
	 * @return mixed        results or original query to fallback to provider search
	 */
	function search( $query ) {
		if ( $this->io->isVerbose() ) {
			$this->io->write( "Searching for $query" );
		}
		try {
			$results = $this->queryAPI( 'query_plugins', [ 'search' => $query ] );
		} catch ( RuntimeException $e ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( $e->getMessage() );
			}
			// fall back to the standard search method
			return $query;
		}
		// query the api
		if ( !empty( $results->plugins ) ) {
			$vendor = $this->repo->getDefaultVendor();
			$out = [];
			foreach ( $results->plugins as $plugin ) {
				$out[] = [
					'name' => "$vendor/{$plugin->slug}",
					'description' => Util::truncate( strip_tags( $plugin->short_description ), 100 ),
					'url' => $plugin->homepage,
				];
			}
			return $out;
		}
		return $query;
	}

	/**
	 * Use the 'newest' plugins feed as a form of cache invalidation.
	 */
	function cache( $providerHash, Cache $cache ) {
		// get the X newest plugins
		$request = [
			'browse' => 'new',
			'per_page' => self::newestNum,
			// disable unnecessary fields to make the response smaller
			'fields' => [
				'description' => false,
				'author' => false,
				'author_profile' => false,
				'contributors' => false,
				'version' => false,
				'requires' => false,
				'tested' => false,
				'compatibility' => false,
				'rating' => false,
				'num_ratings' => false,
				'ratings' => false,
				'homepage' => false,
				'short_description' => false,
			],
		];
		try {
			$response = $this->queryAPI( 'query_plugins', $request );
		} catch ( RuntimeException $e ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( $e->getMessage() );
			}
			// invalidate the cache
			return false;
		}
		$newest = [];
		if ( !empty( $response->plugins ) && is_array( $response->plugins ) ) {
			foreach ( $response->plugins as $plugin ) {
				$newest[] = $plugin->slug;
			}
		}
		// do we even have a provider list and old set of newest to work off of?
		if ( is_array( $providerHash ) && $lastNewest = $cache->read( 'newest.json' ) ) {
			// the newest from the last time we checked
			if ( $lastNewest = json_decode( $lastNewest ) ) {
				$newProviders = array_diff( $newest, $lastNewest );
				// if the number of new providers is equal to number we pulled, assume there may be more and invalidate the cache
				if ( count( $newProviders ) < self::newestNum ) {
					$url = reset( $this->config['url'] );
					foreach ( $newProviders as $name ) {
						// full path to this provider in the SVN repo, no trailing slash
						$providerHash[ $name ] = "$url/$name";
					}
				} else {
					$providerHash = false;
				}
			} else {
				$providerHash = false;
			}
		}
		// save the newest plugins to the cache
		$cache->write( 'newest.json', json_encode( $newest ) );
		return $providerHash;
	}

}
