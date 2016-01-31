<?php

/**
 * Repository definition for the WordPress VIP plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Config\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\Package\CompletePackage;

class WordPressComThemes extends SVNRepositoryConfig {

	protected $config = array(
		'url' => 'https://wpcom-themes.svn.automattic.com/',
		'provider-paths' => array( '/' ),
		'package-paths' => array( '' ),
		'types' => array( 'wordpress-com-theme' => 'wordpress-com' ),
		'provider-filter' => array( __CLASS__, 'filterProvider' ),
		'version-filter' => array( __CLASS__, 'filterVersion' ),
		'package-filter' => array( __CLASS__, 'filterPackage' ),
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

	static function filterPackage( CompletePackage $package ) {
		// set the type to basic wordpress plugin
		$package->setType( 'wordpress-theme' );
	}

}
