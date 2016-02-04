<?php

/**
 * Repository definition for the WordPress.com themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;

class WordPressComThemes extends SVNRepositoryConfig {

	protected $config = [
		'url' => 'https://wpcom-themes.svn.automattic.com/',
		'provider-paths' => [ '/' ],
		'package-paths' => [ '' ],
		'package-types' => [ 'wordpress-theme' => 'wordpress-com' ],
		'name-filter' => [ __CLASS__, 'filterProvider' ],
		'version-filter' => [ __CLASS__, 'filterVersion' ],
	];

	static function filterProvider( $name, $path, $url ) {
		if ( $name === '.ignore' ) {
			return '';
		}
		return $name;
	}

	/**
	 * The WordPress.com themes are not versioned.
	 */
	static function filterVersion( $version ) {
		return 'dev-master';
	}

}
