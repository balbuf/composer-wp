<?php

/**
 * Repository definition for the WordPress themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use BalBuf\ComposerWP\Repository\SVNRepository;
use BalBuf\ComposerWP\Util\Util;

class WordPressThemes extends SVNRepositoryConfig {

	const searchUrl = 'https://api.wordpress.org/themes/info/1.1/';

	protected $config = [
		'url' => 'https://themes.svn.wordpress.org/',
		'package-types' => [ 'wordpress-theme' => 'wordpress-theme' ],
		'package-filter' => [ __CLASS__, 'filterPackage' ],
		'search-handler' => [ __CLASS__, 'search' ],
	];

	protected static $themeInfo = [];

	/**
	 * Filter the package to add dist and other meta information.
	 */
	static function filterPackage( CompletePackage $package, IOInterface $io, PluginInterface $plugin ) {
		list( $vendor, $shortName ) = explode( '/',  $package->getName() );
		// try to get the theme info - may return an array or null/false
		if ( $info = static::getThemeInfo( $shortName, $io ) ) {
			// set the dist info
			$package->setDistType( 'zip' );
			// strip out slashes and spaces
			$version = preg_replace( '/[\\/ ]/', '', $package->getSourceReference() );
			// if there is a version identifier, prepend with a period
			$version = $version ? ".$version" : '';
			// set the dist url
			$package->setDistUrl( 'http://downloads.wordpress.org/theme/' . urlencode( $shortName . $version ) . '.zip' );

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
		} else if ( $info === false ) {
			// false means the package is no longer active
			$package->setAbandoned( true );
		}
	}

	/**
	 * Ask the API about a theme.
	 * @param  string $theme theme slug
	 * @return mixed         array of theme data or false if no data / null if retrieval error
	 */
	static function getThemeInfo( $theme, IOInterface $io ) {
		if ( !array_key_exists( $theme, static::$themeInfo ) ) {
			// ask the API about this theme
			$url = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . urlencode( $theme );
			if ( $io->isVerbose() ) {
				$io->write( "Requesting more information about $theme theme: $url" );
			}
			$response = file_get_contents( $url );
			// was the retrieval successful?
			if ( $response === false ) {
				if ( $io->isVerbose() ) {
					$io->writeError( "Could not retrieve $url" );
				}
				// this allows us to try again
				return null;
			} else {
				$data = json_decode( $response, true );
				// null could either really be null, or a json error
				if ( $data === null ) {
					if ( json_last_error() !== \JSON_ERROR_NONE ) {
						if ( $io->isVerbose() ) {
							$io->writeError( 'JSON error occured: ' . json_last_error_msg() );
						}
						return null;
					}
				}
				if ( $io->isDebug() ) {
					$io->Write( "Theme data: \n" . print_r( $data, true ) );
				}
				static::$themeInfo[ $theme ] = $data;
			}
		}
		return static::$themeInfo[ $theme ];
	}

	/**
	 * Query the WP theme api for results.
	 * @param  string $query search query
	 * @return mixed        results or original query to fallback to provider search
	 */
	static function search( $query, IOInterface $io, SVNRepository $repo ) {
		$response = file_get_contents( self::searchUrl . '?action=query_themes&request[search]=' . urlencode( $query ) );
		// @todo error handling for file get contents
		$results = json_decode( $response, true );
		if ( json_last_error() === \JSON_ERROR_NONE ) {
			if ( !empty( $results['themes'] ) ) {
				$out = [];
				$vendor = $repo->getDefaultVendor();
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
		} else {
			if ( $io->isDebug() ) {
				$io->writeError( 'JSON error occured: ' . json_last_error_msg() );
			}
		}
		return $query;
	}

}
