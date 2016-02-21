<?php

/**
 * Repository definition for the WordPress plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use BalBuf\ComposerWP\Repository\SVNRepository;
use BalBuf\ComposerWP\Util\Util;
use Composer\Cache;

class WordPressPlugins extends SVNRepositoryConfig {

	const searchUrl = 'https://api.wordpress.org/plugins/info/1.0/';
	const newestNum = 100; // how many of the newest plugins to pull for comparison

	protected $config = [
		'url' => 'https://plugins.svn.wordpress.org/',
		'package-paths' => [ '/tags/', '/trunk' ],
		'package-types' => [ 'wordpress-plugin' => 'wordpress-plugin', 'wordpress-muplugin' => 'wordpress-muplugin' ],
		'package-filter' => [ __CLASS__, 'filterPackage' ],
		'search-handler' => [ __CLASS__, 'search' ],
		'cache-handler' => [ __CLASS__, 'cache' ],
		// store the cache for however long the default is - it will likely get invalidated before then
		'cache-ttl' => 'config',
	];

	protected static $pluginInfo = [];

	/**
	 * Filter the package to add dist and other meta information.
	 */
	static function filterPackage( CompletePackage $package, IOInterface $io, PluginInterface $plugin ) {
		list( $vendor, $shortName ) = explode( '/',  $package->getName() );
		// try to get the plugin info - may return an array or null/false
		if ( $info = static::getPluginInfo( $shortName, $io ) ) {
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
		} else if ( $info === null ) {
			// null means the package is no longer active
			$package->setAbandoned( true );
		}
	}

	/**
	 * Ask the API about a plugin.
	 * @param  string $plugin plugin slug
	 * @return mixed         array of plugin data or null if no data / false if retrieval error
	 */
	static function getPluginInfo( $plugin, IOInterface $io ) {
		if ( !array_key_exists( $plugin, static::$pluginInfo ) ) {
			// ask the API about this plugin
			$url = 'https://api.wordpress.org/plugins/info/1.0/' . urlencode( $plugin ) . '.json';
			if ( $io->isVerbose() ) {
				$io->write( "Requesting more information about $plugin plugin: $url" );
			}
			$response = file_get_contents( $url );
			// was the retrieval successful?
			if ( $response === false ) {
				if ( $io->isVerbose() ) {
					$io->writeError( "Could not retrieve $url" );
				}
				// this allows us to try again
				return false;
			} else {
				$data = json_decode( $response, true );
				// null could either really be null, or a json error
				if ( $data === null ) {
					if ( json_last_error() !== \JSON_ERROR_NONE ) {
						if ( $io->isVerbose() ) {
							$io->writeError( 'JSON error occured: ' . json_last_error_msg() );
						}
						return false;
					}
				}
				if ( $io->isDebug() ) {
					$io->Write( "Plugin data: \n" . print_r( $data, true ) );
				}
				static::$pluginInfo[ $plugin ] = $data;
			}
		}
		return static::$pluginInfo[ $plugin ];
	}

	/**
	 * Query the plugins API for information.
	 * @see http://codex.wordpress.org/WordPress.org_API#Plugins
	 * @param  string $action  request action
	 * @param  array $request request data
	 * @return mixed          response
	 */
	static function queryAPI( $action, array $request ) {
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
		// query the api
		if ( $response = file_get_contents( self::searchUrl, false, $ctx ) ) {
			// @todo file get contents error handling
			return unserialize( $response );
		}
	}

	/**
	 * Query the WP plugins api for results.
	 * @param  string $query search query
	 * @return mixed        results or original query to fallback to provider search
	 */
	static function search( $query, IOInterface $io, SVNRepository $repo ) {
		if ( $io->isVerbose() ) {
			$io->write( 'Searching ' . self::searchUrl . ' for ' . $query );
		}
		// query the api
		if ( $results = self::queryAPI( 'query_plugins', [ 'search' => $query ] ) ) {
			if ( !empty( $results->plugins ) ) {
				$vendor = $repo->getDefaultVendor();
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
		}
		return $query;
	}

	/**
	 * Use the 'newest' plugins feed as a form of cache invalidation.
	 */
	static function cache( $providerHash, Cache $cache, SVNRepository $repo ) {
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
		$newest = [];
		if ( $response = self::queryAPI( 'query_plugins', $request ) ) {
			if ( !empty( $response->plugins ) && is_array( $response->plugins ) ) {
				foreach ( $response->plugins as $plugin ) {
					$newest[] = $plugin->slug;
				}
			}
		}
		// do we even have a provider list and old set of newest to work off of?
		if ( is_array( $providerHash ) && $lastNewest = $cache->read( 'newest.json' ) ) {
			// the newest from the last time we checked
			if ( $lastNewest = json_decode( $lastNewest ) ) {
				$newProviders = array_diff( $newest, $lastNewest );
				// if the number of new providers is equal to number we pulled, assume there may be more and invalidate the cache
				if ( count( $newProviders ) < self::newestNum ) {
					$url = $repo->getRepoConfig()[0];
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
