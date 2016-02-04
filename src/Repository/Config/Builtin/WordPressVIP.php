<?php

/**
 * Repository definition for the WordPress VIP plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;

class WordPressVIP extends SVNRepositoryConfig {

	protected $config = [
		'url' => 'https://vip-svn.wordpress.com/plugins/',
		'provider-paths' => [ '/', 'release-candidates/' ],
		'package-paths' => [ '' ],
		'package-types' => [ 'wordpress-plugin' => 'wordpress-vip' ],
		'name-filter' => [ __CLASS__, 'filterProvider' ],
		'version-filter' => [ __CLASS__, 'filterVersion' ],
	];

	static function filterProvider( $name, $path, $url ) {
		if ( $name === 'release-candidates' ) {
			return '';
		}
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

}
