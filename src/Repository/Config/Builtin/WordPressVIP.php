<?php

/**
 * Repository definition for the WordPress VIP plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;

class WordPressVIP extends SVNRepositoryConfig {

	protected $config = [
		'url' => 'https://vip-svn.wordpress.com/plugins/',
		'provider-paths' => [ '/', 'release-candidates/' ],
		'package-paths' => [ '' ],
		'package-types' => [ 'wordpress-plugin' => 'wordpress-vip' ],
		'name-filter' => [ __CLASS__, 'filterProvider' ],
		'version-filter' => [ __CLASS__, 'filterVersion' ],
		'package-filter' => [ __CLASS__, 'filterPackage' ],
		// cache for one week - these change infrequently
		'cache-ttl' => 604800,
	];

	static function filterProvider( $name, $path, $url ) {
		// this is a subdir of plugins that are release candidates, not a plugin itself
		if ( $name === 'release-candidates' ) {
			return '';
		}
		// this plugin is in the release candidate subdir - append '-rc' in case there is
		// an existing plugin of the same name in the repo root
		if ( $path === 'release-candidates/' ) {
			return "$name-rc";
		}
		return $name;
	}

	/**
	 * The WordPress VIP plugins are not versioned and should not be considered stable.
	 */
	static function filterVersion( $version ) {
		return 'dev-master';
	}

	/**
	 * Add some package meta info.
	 */
	static function filterPackage( CompletePackage $package ) {
		// set the VIP plugins landing page as the homepage as some do not have individual overview pages
		$package->setHomepage( 'https://vip.wordpress.com/plugins/' );
	}

}
