<?php

/**
 * Interface which describes a repo defintion.
 */

namespace BalBuf\ComposerWP\Repository\Config;

interface RepositoryConfigInterface {

	/**
	 * Get the full config array.
	 * @return array config values
	 */
	function getConfig();

	/**
	 * Get the full array of config defaults.
	 * @return array config defaults
	 */
	function getConfigDefaults();

	/**
	 * Get the value for the request config key, if set.
	 * @param  string $key config key
	 * @return mixed value
	 */
	function get( $key );

	/**
	 * Set a value for the config key.
	 * @param string $key   config key
	 * @param mixed  $value
	 */
	function set( $key, $value );

	/**
	 * What type of repository type to use?
	 * @return string repo type
	 */
	function getRepositoryType();

}
