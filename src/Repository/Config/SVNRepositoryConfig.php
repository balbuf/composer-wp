<?php

/**
 * SVN Repository config base class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

class SVNRepositoryConfig implements RepositoryConfigInterface {

	protected static $config;

	static function getConfig() {
		return static::$config;
	}

	static function getRepositoryType() {
		return 'wp-svn';
	}

}
