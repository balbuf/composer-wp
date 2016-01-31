<?php

/**
 * Repository definition for the WordPress.com themes repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;

class WordPressComThemes extends SVNRepositoryConfig {

	protected $config = array(
		'url' => 'https://wpcom-themes.svn.automattic.com/',
		'provider-paths' => array( '/' ),
		'package-paths' => array( '' ),
		'types' => array( 'wordpress-theme' => 'wordpress-com' ),
		'name-filter' => array( __CLASS__, 'filterProvider' ),
		'version-filter' => array( __CLASS__, 'filterVersion' ),
	);

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
