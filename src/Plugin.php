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

	const extra_field = 'composer-wp';

	protected $composer;
	protected $io;
	// these are default repos that are named and can be enabled/disabled in composer.json
	// their configuration options are included from an external file
	protected $svnRepos;
	// these repos are enabled by default, unless otherwise disabled
	protected $defaultRepos = array( 'plugins' );

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
		// get the builtin and default repo definitions
		$this->svnRepos = include __DIR__ . '/../inc/builtin-repo-configs.php';
		// the extra data from the composer.jsom
		$extra = $composer->getPackage()->getExtra();
		// drill down to only our options
		$extra = !empty( $extra[ self::extra_field ] ) ? $extra[ self::extra_field ] : array();

		// add the default repos, if desired
		$repos = array();
		// get the user-defined repos first
		if ( !empty( $extra['repositories'] ) ) {
			if ( !is_array( $extra['repositories'] ) ) {
				throw new \Exception( '[extra][' . self::extra_field . '][repositories] should be an array of repository definitions.' );
			}
			foreach ( $extra['repositories'] as $repo ) {
				// see if this includes definition(s) about the default repos
				$defaults = array_intersect_key( $repo, $this->svnRepos );
				// note: this means repo property names should not overlap with default repo names
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
		// add our repo class as an available one to use
		$rm->setRepositoryClass( 'wp-svn', '\\' . __NAMESPACE__ . '\WordPressSVNRepository' );

		// create the repos!
		foreach ( $repos as $name => $definition ) {
			// is this a default svn repo?
			if ( isset( $this->svnRepos[ $name ] ) ) {
				// a falsey value means we will not use this repo
				if ( !$definition ) {
					continue;
				}
				// grab the default configuration, filling in any missing properties
				$repoConfig = $this->svnRepos[ $name ];
				// replace out the default types based on the composer.json vendor mapping
				if ( !empty( $extra['types'] ) ) {
					// make sure this property is set
					$repoConfig += array( 'types' => array() );
					$repoConfig['types'] = array_replace( $repoConfig['types'], $extra['types'] );
				}
				// allow config properties to be overridden by composer.json
				if ( is_array( $definition ) ) {
					// @todo: any sort of recursion?
					$repoConfig = array_replace( $repoConfig, $definition );
				}
				// add the repo!
				$rm->addRepository( $rm->createRepository( 'wp-svn', $repoConfig ) );
			} else {
				// @todo handle additional repo types
			}
		}
	}

	public function onCommand( CommandEvent $event ) {

	}

}