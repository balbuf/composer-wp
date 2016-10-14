<?php

/**
 * Repository definition for the WordPress themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use BalBuf\ComposerWP\Util\Util;
use Composer\Cache;
use RuntimeException;

class WordPressThemes extends SVNRepositoryConfig {

	const apiUrl = 'https://api.wordpress.org/themes/info/1.1/';
	const newestNum = 50;

	protected $themeInfo = [];

	function __construct() {
		$this->config = [
			'url' => 'https://themes.svn.wordpress.org/',
			'package-types' => [ 'wordpress-theme' => 'wordpress-theme' ],
			'package-filter' => [ $this, 'filterPackage' ],
			'search-handler' => [ $this, 'search' ],
			'cache-handler' => [ $this, 'cache' ],
			// store the cache for however long the default is - it will likely get invalidated before then
			'cache-ttl' => 'config',
			'trust-cert' => true,
		];
		parent::__construct();
	}

	/**
	 * Filter the package to add dist and other meta information.
	 */
	function filterPackage( CompletePackage $package ) {
		list( $vendor, $shortName ) = explode( '/', $package->getName() );
		// try to get the theme info - may return an array or null/false
		try {
			$info = $this->getThemeInfo( $shortName );
		} catch ( RuntimeException $e ) {
			if ( $io->isVerbose() ) {
				$io->writeError( $e->getMessage() );
			}
			// this allows us to try again for this theme
			return;
		}
		if ( $info ) {
			// set the dist info
			$package->setDistType( 'zip' );
			// strip out slashes and spaces
			$version = preg_replace( '/[\\/ ]/', '', $package->getSourceReference() );
			// if there is a version identifier, prepend with a period
			$version = $version ? ".$version" : '';
			// set the dist url
			$package->setDistUrl( 'https://downloads.wordpress.org/theme/' . urlencode( $shortName . $version ) . '.zip' );

			// set some additional meta info
			// this is inconsequential to the solver, but it gets stored in composer.lock
			// and appears when running `composer show vendor/package`
			if ( isset( $info['sections']['description'] ) ) {
				$package->setDescription( $info['sections']['description'] );
			}
			if ( !empty( $info['author'] ) ) {
				$package->setAuthors( [ [ 'name' => $info['author'] ] ] );
			}
			if ( !empty( $info['tags'] ) ) {
				$package->setKeywords( $info['tags'] );
			}
			// URL-ready slug
			$slug = urlencode( $shortName );
			$package->setSupport( [
				'forum' => "https://wordpress.org/support/theme/$slug/",
				'source' => "https://themes.trac.wordpress.org/browser/$slug/",
				'docs' => "https://wordpress.org/themes/$slug/",
			] );
			$package->setHomepage( "https://wordpress.org/themes/$slug/" );
		} else {
			// false means the package is no longer active
			$package->setAbandoned( true );
		}
	}

	/**
	 * Query the themes API for information.
	 * @see http://codex.wordpress.org/WordPress.org_API#Themes
	 * @param  string $action request action
	 * @param  array $request request data
	 * @return mixed          response
	 */
	function queryAPI( $action, $request ) {
		$url = self::apiUrl . '?' . http_build_query( [ 'action' => $action, 'request' => $request ] );
		if ( $this->io->isVerbose() ) {
			$this->io->write( "Fetching $url" );
		}
		$response = file_get_contents( $url );
		if ( $response === false ) {
			throw new RuntimeException( "Could not retrieve $url" );
		} else {
			$response = json_decode( $response, true );
			if ( json_last_error() !== \JSON_ERROR_NONE ) {
				throw new RuntimeException( 'JSON error occured: ' . json_last_error_msg() );
			}
			return $response;
		}
	}

	/**
	 * Ask the API about a theme.
	 * @param  string $theme theme slug
	 * @return mixed         array of theme data or false if no data / null if retrieval error
	 */
	function getThemeInfo( $theme ) {
		if ( !array_key_exists( $theme, $this->themeInfo ) ) {
			if ( $this->io->isVerbose() ) {
				$this->io->write( "Requesting more information about $theme theme." );
			}
			// ask the API about this theme
			$data = $this->queryAPI( 'theme_information', [ 'slug' => $theme ] );
			$this->themeInfo[ $theme ] = $data;
		}
		return $this->themeInfo[ $theme ];
	}

	/**
	 * Query the WP theme api for results.
	 * @param  string $query search query
	 * @return mixed        results or original query to fallback to provider search
	 */
	function search( $query ) {
		if ( $this->io->isVerbose() ) {
			$this->io->write( 'Searching ' . self::apiUrl . ' for ' . $query );
		}
		try {
			$results = $this->queryAPI( 'query_themes', [ 'search' => $query ] );
		} catch ( RuntimeException $e ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( $e->getMessage() );
			}
			// fall back to the standard search method
			return $query;
		}
		if ( !empty( $results['themes'] ) ) {
			$out = [];
			$vendor = $this->repo->getDefaultVendor();
			foreach ( $results['themes'] as $theme ) {
				// fairly confident all of the fields below will be provided for a given theme
				$out[] = [
					'name' => "$vendor/{$theme['slug']}",
					// truncate as some of these descriptions can get out of hand
					'description' => Util::truncate( strip_tags( $theme['description'] ), 100 ),
					'url' => $theme['homepage'],
				];
			}
			return $out;
		}
		return $query;
	}

	/**
	 * Use the 'newest' themes feed as a form of cache invalidation.
	 */
	function cache( $providerHash, Cache $cache ) {
		// get the X newest plugins
		$request = [
			'browse' => 'new',
			'per_page' => self::newestNum,
			// disable unnecessary fields to make the response smaller
			'fields' => [
				'name' => false,
				'version' => false,
				'rating' => false,
				'downloaded' => false,
				'downloadlink' => false,
				'last_updated' => false,
				'homepage' => false,
				'tags' => false,
				'template' => false,
				'screenshot_url' => false,
				'preview_url' => false,
				'author' => false,
				'description' => false,
			],
		];
		try {
			$response = $this->queryAPI( 'query_themes', $request );
		} catch ( RuntimeException $e ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( $e->getMessage() );
			}
			// invalidate the cache
			return false;
		}
		$newest = [];
		if ( !empty( $response['themes'] ) && is_array( $response['themes'] ) ) {
			foreach ( $response['themes'] as $theme ) {
				$newest[] = $theme['slug'];
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
