<?php

/**
 * Repository definition for the WordPress plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class WordPressPluginsRepository extends SVNRepositoryConfig {

	protected static $config = array(
		'url' => 'http://plugins.svn.wordpress.org/',
		'package-paths' => array( '/tags/', '/trunk' ),
		'types' => array( 'wordpress-plugin' => 'wordpress-plugin', 'wordpress-muplugin' => 'wordpress-muplugin' ),
		'package-filter' => array( __CLASS__, 'filterPackage' ),
	);

	protected static $pluginInfo = array();

	/**
	 * Filter the package to add dist and other meta information.
	 */
	static function filterPackage( CompletePackage $package, IOInterface $io, PluginInterface $plugin ) {
		$shortName = substr( strstr( $package->getName(), '/' ), 1 );
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
			if ( isset( $info['short_description'] ) ) {
				$package->setDescription( $info['short_description'] );
			}
			if ( isset( $info['contributors'] ) ) {
				$authors = array();
				foreach ( $info['contributors'] as $name => $homepage ) {
					$authors[] = array(
						'name' => $name,
						'homepage' => $homepage,
					);
				}
				$package->setAuthors( $authors );
			}
			// URL-ready slug
			$pluginSlug = urlencode( $shortName );
			$package->setSupport( array(
				'forum' => "https://wordpress.org/support/plugin/$pluginSlug/",
				'source' => "http://plugins.trac.wordpress.org/browser/$pluginSlug/",
			) );
			$package->setHomepage( "https://wordpress.org/plugins/$pluginSlug/" );
			// @todo: add optional wordpress version check
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
			$url = 'https://api.wordpress.org/plugins/info/1.0/' . urldecode( $plugin ) . '.json';
			if ( $io->isVerbose() ) {
				$io->write( "Requesting more information about $plugin: $url" );
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
				if ( $io->isVeryVerbose() ) {
					$io->Write( "Plugin data: \n" . print_r( $data, true ) );
				}
				static::$pluginInfo[ $plugin ] = $data;
			}
		}
		return static::$pluginInfo[ $plugin ];
	}

}
