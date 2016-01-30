<?php

/**
 * Repository definition for the WordPress plugins repository.
 */

namespace BalBuf\ComposerWP\Repository\Builtin;

use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;

class WordPressPluginsRepository extends SVNRepositoryConfig {

	protected static $config = array(
		'url' => 'http://plugins.svn.wordpress.org/',
		'package-paths' => array( '/tags/', '/trunk' ),
		'types' => array( 'wordpress-plugin' => 'wordpress-plugin', 'wordpress-muplugin' => 'wordpress-muplugin' ),
		'package-filter' => '',
	);

}
