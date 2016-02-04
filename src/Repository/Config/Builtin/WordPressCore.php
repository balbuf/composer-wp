<?php

/**
 * Repository definition for the WordPress core SVN repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class WordPressCore extends SVNRepositoryConfig {

	protected $config = [
		'url' => 'https://core.svn.wordpress.org/',
		'provider-paths' => [ '' ],
		'package-paths' => [ '/tags/', '/trunk' ],
		'package-types' => [ 'wordpress-core' => [ 'wordpress', 'wordpress-core' ] ],
		'name-filter' => [ __CLASS__, 'filterProvider' ],
		'package-filter' => [ __CLASS__, 'filterPackage' ],
	];

	/**
	 * The provider name will be empty, so fill it in.
	 */
	static function filterProvider( $name, $path, $url ) {
		if ( $name === '' ) {
			return 'wordpress';
		}
	}

	static function filterPackage( CompletePackage $package, IOInterface $io, PluginInterface $plugin ) {
		// strip out "tags", slashes, and spaces
		$version = preg_replace( '/tags|[\\/ ]/', '', $package->getSourceReference() );
		// trunk does not have a dist version
		if ( $version !== 'trunk' ) {
			// set the dist info
			$package->setDistType( 'zip' );
			// if there is a version identifier, prepend with a period
			$version = "-$version";
			// set the dist url
			$package->setDistUrl( 'https://wordpress.org/wordpress' . urlencode( $version ) . '.zip' );
		}

		// set some additional meta info
		// this is inconsequential to the solver, but it gets stored in composer.lock
		// and appears when running `composer show vendor/package`
		$package->setDescription( 'WordPress is web software you can use to create a beautiful website, blog, or app.' );
		$package->setSupport( [
			'forum' => 'https://wordpress.org/support/',
			'source' => 'https://core.trac.wordpress.org/browser/' . $package->getSourceReference(),
			'docs' => 'https://codex.wordpress.org/Main_Page',
		] );
		$package->setHomepage( 'https://wordpress.org/' );
	}

}
