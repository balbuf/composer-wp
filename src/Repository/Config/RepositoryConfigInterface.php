<?php

/**
 * Interface which describes a repo defintion.
 */

namespace BalBuf\ComposerWP\Repository\Config;

interface RepositoryConfigInterface {

	static function getConfig();

	static function getRepositoryType();

}
