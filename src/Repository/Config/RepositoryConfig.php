<?php

/**
 * Base repository config class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryInterface;


class RepositoryConfig implements RepositoryConfigInterface {

	const repoType = null;
	protected $config;
	protected static $configDefaults = [];
	protected $composer;
	protected $io;
	protected $plugin;
	protected $repo;

	function __construct( $config = [] ) {
		// replace these defaults into the config values of the instantiated child class
		$this->config = array_replace( static::$configDefaults, $this->config ?: $config );
	}

	/**
	 * Get all the config values.
	 * @return array
	 */
	function getConfig() {
		return $this->config;
	}

	/**
	 * Set a config option.
	 * @param string $key
	 * @param mixed  $value
	 */
	function set( $key, $value ) {
		$this->config[ $key ] = $value;
	}

	/**
	 * Get a config option.
	 * @param  string $key
	 * @return mixed      value
	 */
	function get( $key ) {
		if ( isset( $this->config[ $key ] ) ) {
			return $this->config[ $key ];
		}
	}

	/**
	 * Get the defaults for this config type.
	 * @return array default values
	 */
	function getConfigDefaults() {
		return static::$configDefaults;
	}

	/**
	 * Get the repo type that this config class corresponds to.
	 * @return string repo type
	 */
	function getRepositoryType() {
		return static::repoType;
	}

	/**
	 * Set the composer instance for this config.
	 * @param Composer $composer
	 */
	function setComposer( Composer $composer ) {
		$this->composer = $composer;
	}

	/**
	 * Get the composer instance for this config.
	 * @return Composer
	 */
	function getComposer() {
		return $this->composer;
	}

	/**
	 * Set the IO instance for this config.
	 * @param IOInterface $io
	 */
	function setIO( IOInterface $io ) {
		$this->io = $io;
	}

	/**
	 * Get the IO instance for this config.
	 * @return IOInterface
	 */
	function getIO() {
		return $this->io;
	}

	/**
	 * Set the plugin instance for this config.
	 * @param PluginInterface $plugin
	 */
	function setPlugin( PluginInterface $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Get the plugin instance for this config.
	 * @return PluginInterface
	 */
	function getPlugin() {
		return $this->plugin;
	}

	/**
	 * Set the repo instance for this config.
	 * @param RepositoryInterface $repo
	 */
	function setRepo( RepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Get the repo instance for this config.
	 * @return RepositoryInterface
	 */
	function getRepo() {
		return $this->repo;
	}

}
