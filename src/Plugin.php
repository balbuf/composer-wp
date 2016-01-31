<?php

/**
 * TODO
 * - allow directories of plugin zips to be specified !
 * - ssh into a server to get at zipped plugins
 * - github hosted plugins possibly missing composer.json
 * - composer autoload order
 */

namespace BalBuf\ComposerWP;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginEvents;
use Composer\Config;
use Composer\Installer\InstallerEvent;
use Composer\Plugin\CommandEvent;


class Plugin implements PluginInterface, EventSubscriberInterface {

	// the plugin properties are defined in this 'extra' field
	const extra_field = 'composer-wp';
	protected $composer;
	protected $io;
	// these are builtin repos that are named and can be enabled/disabled in composer.json
	// the name maps to a class in Repository\Config\Builtin which defines its config options
	protected $builtinRepos = array(
		'plugins' => 'WordPressPlugins',
		'themes' => 'WordPressThemes',
		'core' => 'WordPressCore',
		'develop' => 'WordPressDevelop',
		'wpcom-themes' => '',
		'vip-plugins' => '',
	);
	// these repos are enabled by default, unless otherwise disabled
	protected $defaultRepos = array( 'plugins', 'core' );

	/**
	 * Instruct the plugin manager to subscribe us to these events.
	 */
	public static function getSubscribedEvents() {
		return array(
			PluginEvents::COMMAND => array(
				array('onCommand', 0),
			),
		);
	}

	/**
	 * Set up our repos.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		// store composer and io objects for reuse
		$this->composer = $composer;
		$this->io = $io;
		// the extra data from the composer.jsom
		$extra = $composer->getPackage()->getExtra();
		// drill down to only our options
		$this->extra = !empty( $extra[ self::extra_field ] ) ? $extra[ self::extra_field ] : array();
		// add the default repos, if desired
		$repos = array();
		// get the user-defined repos first
		if ( !empty( $this->extra['repositories'] ) ) {
			if ( !is_array( $this->extra['repositories'] ) ) {
				throw new \Exception( '[extra][' . self::extra_field . '][repositories] should be an array of repository definitions.' );
			}
			foreach ( $this->extra['repositories'] as $repo ) {
				// see if this includes definition(s) about the builtin repos
				$defaults = array_intersect_key( $repo, $this->builtinRepos );
				// note: this means repo property names should not overlap with builtin repo names
				if ( count( $defaults ) ) {
					// add these to the repos array, only if not defined already
					$repos += $defaults;
				} else {
					// custom repo - add as-is, to be parsed later
					$repos[] = $repo;
				}
			}
		}
		// add the default repos - they will only be added if not previously defined
		$repos += array_fill_keys( $this->defaultRepos, true );

		// the repo manager
		$rm = $this->composer->getRepositoryManager();
		// add our repo classes as available ones to use
		$rm->setRepositoryClass( 'wp-svn', '\\' . __NAMESPACE__ . '\\Repository\\SVNRepository' );

		// user-defined type mappings
		$types = array();
		if ( !empty( $this->extra['vendors'] ) ) {
			foreach ( $this->extra['vendors'] as $vendor => $type ) {
				$types += array( $type => array() );
				$types[ $type ][] = $vendor;
			}
		}

		// create the repos!
		foreach ( $repos as $name => $definition ) {
			// is this a builtin repo?
			if ( isset( $this->builtinRepos[ $name ] ) ) {
				// a falsey value means we will not use this repo
				if ( !$definition ) {
					continue;
				}
				// grab the repo config
				$configClass = '\\' . __NAMESPACE__ . '\\Repository\\Config\\Builtin\\' . $this->builtinRepos[ $name ];
				$repoConfig = new $configClass();
				// replace out the default types based on the composer.json vendor mapping
				$repoConfig->set( 'types', array_replace( $repoConfig->get( 'types' ), array_intersect_key( $types, $repoConfig->get( 'types' ) ) ) );
				// allow config properties to be overridden by composer.json
				if ( is_array( $definition ) ) {
					// replace out these values
					foreach ( $definition as $key => $value ) {
						$repoConfig->set( $key, $value );
					}
				}
				$repoConfig->set( 'plugin', $this );
				// add the repo!
				$rm->addRepository( $rm->createRepository( $repoConfig->getRepositoryType(), $repoConfig ) );
			} else {
				// @todo handle additional repo types
			}
		}
	}

	public function onCommand( CommandEvent $event ) {

	}

	/**
	 * Get the extra data pertinent to this plugin.
	 * @return array extra data properties
	 */
	public function getExtra() {
		return $this->extra;
	}

	/**
	 * Get the composer object that this plugin is loaded in.
	 * @return Composer composer instance
	 */
	public function getComposer() {
		return $this->composer;
	}

}
