<?php
/**
 * SVN Repository config class for user-defined repos.
 */

namespace BalBuf\ComposerWP\Repository\Config;

class UserSVNRepositoryConfig extends SVNRepositoryConfig {

	function __construct( $config = array() ) {
		$this->config = $config;
		parent::__construct();
	}

}
