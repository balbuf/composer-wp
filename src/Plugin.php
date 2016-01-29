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

	protected $composer;
	protected $io;
	protected $pool;
	protected $repo;

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

	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
		$this->io = $io;
		$repoConfig = array(
			'url' => 'http://plugins.svn.wordpress.org/',
			'dist-url' => 'https://downloads.wordpress.org/plugin/%name%.%version%.zip',
			'homepage-url' => 'https://wordpress.org/plugins/%name%/',
			'vendors' => array( 'wpackagist-plugin' => 'wordpress-plugin' ),
		);
		$rm = $this->composer->getRepositoryManager();
		$rm->setRepositoryClass( 'wp-svn', '\\' . __NAMESPACE__ . '\WordPressSVNRepository' );
		$repo = $rm->createRepository( 'wp-svn', $repoConfig );
		$rm->addRepository( $repo );
	}

	public function onCommand(CommandEvent $event) {

	}

}