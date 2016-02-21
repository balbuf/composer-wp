<?php

/**
 * Repository definition for the WordPress.com themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;

class WordPressComThemes extends SVNRepositoryConfig {

	const apiUrl = 'https://public-api.wordpress.com/rest/v1.1/themes/';
	protected $themeInfo;
	protected $retiredThemes;

	function __construct() {
		$this->config = [
			'url' => 'https://wpcom-themes.svn.automattic.com/',
			'provider-paths' => [ '/' ],
			'package-paths' => [ '' ],
			'package-types' => [ 'wordpress-theme' => 'wordpress-com' ],
			'name-filter' => [ $this, 'filterProvider' ],
			'version-filter' => [ $this, 'filterVersion' ],
			'package-filter' => [ $this, 'filterPackage' ],
			'search-handler' => [ $this, 'search' ],
			'cache-handler' => [ $this, 'cache' ],
			// cache for one week - these rarely change
			'cache-ttl' => 604800,
		];
		parent::__construct();
	}

	function filterProvider( $name, $path, $url ) {
		// this is only relevant if we fall back to pulling packages from SVN
		if ( $name === '.ignore' ) {
			return '';
		}
		return $name;
	}

	/**
	 * The WordPress.com themes are not versioned in the SVN repo.
	 */
	function filterVersion( $version ) {
		return 'dev-master';
	}

	/**
	 * Filter the package to add dist and other meta information.
	 */
	function filterPackage( CompletePackage $package ) {
		list( $vendor, $shortName ) = explode( '/', $package->getName() );
		if ( ( $themeInfo = $this->getThemeInfo() ) && isset( $themeInfo[ $shortName ] ) ) {
			$info = $themeInfo[ $shortName ];
			// set the dist info
			$package->setDistType( 'zip' );
			$package->setDistUrl( $info['download_uri'] );
			// additional meta info
			$package->setDescription( $info['description'] );
			$package->setAuthors( [ [ 'name' => $info['author'], 'homepage' => $info['author_uri'] ] ] );
			$package->setHomepage( $info['theme_uri'] );
			// is this theme retired?
			if ( in_array( $shortName, $this->getRetiredThemes() ) ) {
				$package->setAbandoned( true );
			}
		}
	}

	/**
	 * Query the WP.com API for all the themes.
	 * There are few enough that it makes sense to load all data at once.
	 * Filter down to only the downloadable ones.
	 * @return array theme info array
	 */
	function getThemeInfo() {
		if ( !isset( $this->themeInfo ) ) {
			// maybe we have it in the cache
			$cache = $this->repo->getCache();
			if ( $themeInfo = $cache->read( 'themes.json' ) ) {
				$themeInfo = json_decode( $themeInfo, true );
				if ( json_last_error() !== \JSON_ERROR_NONE && $this->io->isVerbose() ) {
					$this->io->writeError( 'JSON decoding error from cached theme info ' . json_last_error_msg() );
				}
			}
			// try to pull from the API!
			if ( empty( $themeInfo ) ) {
				// only request the fields we need
				$url = self::apiUrl . '?' . http_build_query( [ 'fields' => 'name,author,author_uri,theme_uri,description,download_uri', 'retired' => 1 ] );
				if ( $this->io->isVerbose() ) {
					$this->io->write( "Fetching $url" );
				}
				$response = file_get_contents( $url );
				if ( $response === false ) {
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( "Could not retrieve $url" );
					}
				} else {
					$results = json_decode( $response, true );
					if ( json_last_error() !== \JSON_ERROR_NONE ) {
						if ( $this->io->isVerbose() ) {
							$this->io->writeError( 'JSON decoding error from API results ' . json_last_error_msg() );
						}
					} else {
						$themeInfo = [];
						if ( !empty( $results['themes'] ) && is_array( $results['themes'] ) ) {
							foreach ( $results['themes'] as $slug => $info ) {
								// if download URI is empty, this means the theme is not free
								if ( !empty( $info['download_uri'] ) ) {
									$themeInfo[ $slug ] = $info;
								}
							}
							// update cache, if we have a TTL
							if ( $this->get( 'cache-ttl' ) ) {
								$cache->write( 'themes.json', json_encode( $themeInfo ) );
							}
						}
					}
				}
			}
			$this->themeInfo = $themeInfo ?: [];
		}
		return $this->themeInfo;
	}

	/**
	 * Get the themes which are now retired, so we can mark them as abandoned.
	 * @return array  theme slugs
	 */
	function getRetiredThemes() {
		if ( !isset( $this->retiredThemes ) ) {
			$cache = $this->repo->getCache();
			if ( $retiredThemes = $cache->read( 'retired.json' ) ) {
				$retiredThemes = json_decode( $retiredThemes );
				if ( json_last_error() !== \JSON_ERROR_NONE ) {
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( 'JSON decoding error from cached retired themes ' . json_last_error_msg() );
					}
				}
			} else {
				$url = 'https://theme.wordpress.com/all-retired/?slugs';
				if ( $this->io->isVerbose() ) {
					$this->io->write( "Fetching retired WP.com themes from $url" );
				}
				$html = file_get_contents( $url );
				if ( $html === false ) {
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( "Could not retrieve $url" );
					}
				} else {
					// pluck out the slugs for public themes
					if ( preg_match_all( '/^pub\\/([\\w-]+)$/m', $html, $matches ) ) {
						$retiredThemes = $matches[1];
					}
					// update cache, if we have a TTL
					if ( $this->get( 'cache-ttl' ) ) {
						$cache->write( 'retired.json', json_encode( $retiredThemes ) );
					}
				}
			}
			$this->retiredThemes = $retiredThemes ?: [];
		}
		return $this->retiredThemes;
	}

	/**
	 * Search the theme data for matching themes.
	 * @param  string $query search query
	 * @return mixed        results or original query to fallback to provider search
	 */
	function search( $query ) {
		if ( $this->io->isVerbose() ) {
			$this->io->write( "Searching WordPress.com themes for $query" );
		}
		if ( $themeInfo = $this->getThemeInfo() ) {
			$vendor = $this->repo->getDefaultVendor();
			// break the query on spaces, escape for regex matching
			$regex = '{' . implode( '|', array_map( 'preg_quote', preg_split( '/\s+/', $query ) ) ) . '}i';
			$out = [];
			foreach ( $themeInfo as $slug => $info ) {
				// see if the query matches in the slug, name, description, or author
				if ( !count( preg_grep( $regex, [ $slug, $info['name'], $info['author'], $info['description'] ] ) ) ) {
					continue;
				}
				$out[] = [
					'name' => "$vendor/$slug",
					'description' => $info['description'],
					'url' => $info['theme_uri'],
				];
			}
			return $out;
		}
		// fall back to provider search
		return $query;
	}

	/**
	 * Take over the provider listing via the API as the preferred method.
	 */
	function cache( $providerHash ) {
		if ( $themeInfo = $this->getThemeInfo() ) {
			$providerHash = [];
			$url = reset( $this->config['url'] );
			foreach ( $themeInfo as $slug => $info ) {
				$providerHash[ $slug ] = "$url/$slug";
			}
		}
		return $providerHash;
	}

}
