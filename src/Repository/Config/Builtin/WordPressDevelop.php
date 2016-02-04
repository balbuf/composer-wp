<?php

/**
 * Repository definition for the WordPress develop SVN repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class WordPressDevelop extends SVNRepositoryConfig {

	protected $config = [
		'url' => 'https://develop.svn.wordpress.org/',
		'provider-paths' => [ '' ],
		'package-paths' => [ '/tags/', '/trunk' ],
		'package-types' => [ 'wordpress-develop' => [ 'wordpress', 'wordpress-core' ] ],
		'name-filter' => [ __CLASS__, 'filterProvider' ],
		'package-filter' => [ __CLASS__, 'filterPackage' ],
	];

	/**
	 * The provider name will be empty, so fill it in.
	 */
	static function filterProvider( $name, $path, $url ) {
		if ( $name === '' ) {
			return 'develop';
		}
	}

	static function filterPackage( CompletePackage $package, IOInterface $io, PluginInterface $plugin ) {
		$package->setHomepage( 'https://wordpress.org/' );
		$package->setDescription( 'WordPress develop repo offering source files, unit tests, and i18n tools.' );
	}

}
