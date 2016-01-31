<?php

/**
 * Base repository config class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

class RepositoryConfig implements RepositoryConfigInterface {

	const repoType = null;
	protected $config;
	protected static $configDefaults = array();

	function __construct( $config = array() ) {
		// replace these defaults into the config values of the instantiated child class
		$this->config = array_replace( static::$configDefaults, $this->config ?: $config );
	}

	function getConfig() {
		return $this->config;
	}

	function set( $key, $value ) {
		$this->config[ $key ] = $value;
	}

	function get( $key ) {
		if ( isset( $this->config[ $key ] ) ) {
			return $this->config[ $key ];
		}
	}

	function getConfigDefaults() {
		return static::$configDefaults;
	}

	function getRepositoryType() {
		return static::repoType;
	}

}
