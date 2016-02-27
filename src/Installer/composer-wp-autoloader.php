<?php
/**
 * Plugin Name: Composer-WP Autoloader
 * Plugin URI: https://github.com/balbuf/composer-wp/
 * Description: An autoloader that enables standard plugins managed by composer to be loaded as must-use plugins. The autoloaded plugins are included during mu-plugin loading. An asterisk (*) next to the name of the plugin designates the plugins that have been autoloaded. Adapted from the Bedrock autoloader (https://roots.io/).
 * Version: 1.0.0
 * Author: balbuf
 * Author URI: https://github.com/balbuf
 * License: MIT License
 */

namespace BalBuf\ComposerWP;

if ( !is_blog_installed() ) {
	return;
}

class MUPluginsAutoloader {

	const package_data_file = 'composer-wp-packages.json';
	protected $plugins = [];

	function __construct() {
		add_filter( 'show_advanced_plugins', [ $this, 'pluginsListTable' ], 0, 2 );
		$this->loadPlugins();
	}

	/**
	 * Load up the mu-plugin packages!
	 */
	function loadPlugins() {
		// does the package data file exist and is it readable?
		$dataFile = __DIR__ . '/' . self::package_data_file;
		if ( !is_readable( $dataFile ) ) {
			return;
		}
		// open file and parse json data
		$packageData = json_decode( file_get_contents( $dataFile ), true );
		// do we have packages? maybe no if json/file error
		if ( !empty( $packageData['packages'] ) ) {
			foreach ( $packageData['packages'] as $pluginFile ) {
				if ( file_exists( $pluginFile = __DIR__ . '/' . $pluginFile ) ) {
					$this->plugins[] = $pluginFile;
				}
			}
		}
		foreach ( $this->plugins as $pluginFile ) {
			include_once( $pluginFile );
		}
		$this->pluginHooks();
	}

	/**
	 * Inject autoloaded plugin data to be shown in the 'Must-Use' section of the plugins manager.
	 *
	 * @filter show_advanced_plugins
	 */
	function pluginsListTable( $value, $type ) {
		// take our action at the 'dropins' point, because the regular mu-plugins have already been enumerated
		if ( $type === 'dropins' ) {
			$muPlugins = [];
			foreach ( $this->plugins as $pluginFile ) {
				$muPlugins[ $pluginFile ] = get_plugin_data( $pluginFile, false, false );
				// we assume Name is set because it would not be in the package data file otherwise
				$muPlugins[ $pluginFile ]['Name'] .= ' *';
			}
			$GLOBALS['plugins']['mustuse'] += $muPlugins;
		}
		return $value;
	}

	/**
	 * This accounts for the plugin hooks that would run if the plugins were
	 * loaded as usual. Plugins are removed by deletion, so there's no way
	 * to deactivate or uninstall.
	 */
	protected function pluginHooks() {
		// @todo come back to this
	}

}

new MUPluginsAutoloader;
