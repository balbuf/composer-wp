<?php
/**
 * Plugin Name: Composer-WP Autoloader
 * Plugin URI: https://github.com/balbuf/composer-wp/
 * Description: Enables standard plugins managed by composer to be autoloaded as must-use plugins. These plugins are included during mu-plugin loading. An asterisk (*) next to the name of the plugin designates the plugins that have been autoloaded. <em>Adapted from the <a href="https://roots.io/">Bedrock autoloader</a>.</em>
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
	protected $packageData = [];
	protected $plugins = [];

	function __construct() {
		// does the package data file exist and is it readable?
		$dataFile = __DIR__ . '/' . self::package_data_file;
		if ( !is_readable( $dataFile ) ) {
			return;
		}
		// open file and parse json data
		$this->packageData = json_decode( file_get_contents( $dataFile ), true );
		if ( json_last_error() !== \JSON_ERROR_NONE ) {
			return;
		}
		// include the composer autoload file
		if ( defined( 'COMPOSER_AUTOLOADER' ) ) {
			$autoloadPath = \COMPOSER_AUTOLOADER;
		} else if ( !empty( $this->packageData['autoloader']['path'] ) ) {
			$autoloadPath = $this->packageData['autoloader']['path'];
			if ( !empty( $this->packageData['autoloader']['relativeTo'] ) ) {
				if ( defined( $this->packageData['autoloader']['relativeTo'] ) ) {
					$autoloadPath = constant( $this->packageData['autoloader']['relativeTo'] ) . "/$autoloadPath";
				}
			}
		}
		if ( !empty( $autoloadPath ) && is_readable( $autoloadPath ) ) {
			include_once( $autoloadPath );
		}
		$this->loadPlugins();
		add_filter( 'show_advanced_plugins', [ $this, 'pluginsListTable' ], 0, 2 );
	}

	/**
	 * Load up the mu-plugin packages!
	 */
	function loadPlugins() {
		// do we have packages? maybe no if json/file error
		if ( !empty( $this->packageData['packages'] ) ) {
			// get the mu-plugins path relative to plugins so that plugins_url functions work properly
			$muPath = $this->muPluginsRelPath();
			foreach ( $this->packageData['packages'] as $pluginFile ) {
				if ( file_exists( $realPath = __DIR__ . '/' . $pluginFile ) ) {
					$this->plugins[] = $realPath;
					// in case this directory is symlinked, inform WP about the symlink vs. real path
					wp_register_plugin_realpath( "$muPath/$pluginFile" );
					include_once( $realPath );
				}
			}
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

	/**
	 * Get the relative path between the plugins/ dir and mu-plugins/ dir.
	 * @return string plugins-relative path to mu-plugins
	 */
	protected function muPluginsRelPath() {
		$pluginsPath = rtrim( wp_normalize_path( WP_PLUGIN_DIR ), '/' );
		$from = explode( '/', $pluginsPath );
		$to = explode( '/', rtrim( wp_normalize_path( WPMU_PLUGIN_DIR ), '/' ) );
		// find where the paths diverge
		for ( $i = 0; isset( $from[ $i ], $to[ $i ] ); $i++ ) {
			if ( $from[ $i ] !== $to[ $i ] ) {
				break;
			}
		}
		return "$pluginsPath/" . str_repeat( '../', count( $from ) - $i ) . implode( '/', array_slice( $to, $i ) );
	}

}

new MUPluginsAutoloader;
