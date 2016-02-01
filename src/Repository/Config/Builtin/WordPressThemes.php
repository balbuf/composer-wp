<?php

/**
 * Repository definition for the WordPress themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class WordPressThemes extends SVNRepositoryConfig {

	protected $config = array(
		'url' => 'https://themes.svn.wordpress.org/',
		'package-types' => array( 'wordpress-theme' => 'wordpress-theme' ),
		'package-filter' => array( __CLASS__, 'filterPackage' ),
	);

	protected static $themeInfo = array();

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
				$package->setAuthors( array( array( 'name' => $info['author'] ) ) );
			}
			if ( !empty( $info['tags'] ) ) {
				$package->setKeywords( $info['tags'] );
			}
			// URL-ready slug
			$slug = urlencode( $shortName );
			$package->setSupport( array(
				'forum' => "https://wordpress.org/support/theme/$slug/",
				'source' => "https://themes.trac.wordpress.org/browser/$slug/",
				'docs' => "https://wordpress.org/themes/$slug/",
			) );
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

}
