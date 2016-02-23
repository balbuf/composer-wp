<?php

/**
 * TODO
 * - for search commands that use APIs, also search on package name
 * - global config for vendor aliases / repos / etc?
 * - alternatively use ZipArchive for local handling
 * - how are the non-ascii package names handled?
 * - github repo type: provide one or more URLs to github repos or users/orgs; those repos will be scanned for themes and plugins
 * - custom installer and mu-plugin autoloader
 * - git alternative for core and develop
 * - add unit tests
 * - add readme
 * - create a script to scan the headers of every plugin to identify anomolies
 * - caching for SSH zip repos
 * - cache plugin/theme info for .org repos
 * - allow additional ssh options set via environment vars
 * - figure out what is going on with the findPackage(s) methods
 * - look into whether the 'replaces' packages are generating extraneous calls to the SVN repo
 * - add a check to see if there is a new version of the plugin available and provide notice if so
 * - envato repo type
 */

namespace BalBuf\ComposerWP;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Config;
use BalBuf\ComposerWP\Repository\Config\RepositoryConfigInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use BalBuf\ComposerWP\Util\SSHFilesystem;


class Plugin implements PluginInterface, EventSubscriberInterface {

	// the plugin properties are defined in this 'extra' field
	const extra_field = 'composer-wp';
	protected $composer;
	protected $io;
	protected $extra;
	// these are builtin repos that are named and can be enabled/disabled in composer.json
	// the name maps to a class in Repository\Config\Builtin which defines its config options
	protected $builtinRepos = [
		'plugins' => 'WordPressPlugins',
		'themes' => 'WordPressThemes',
		'core' => 'WordPressCore',
		'develop' => 'WordPressDevelop',
		'wpcom-themes' => 'WordPressComThemes',
		'vip-plugins' => 'WordPressVIP',
	];
	// these repos are auto-enabled if their vendor names are found in the root package, unless otherwise disabled
	protected $autoLoadRepos = [ 'plugins', 'core', 'themes', 'wpcom-themes', 'vip-plugins' ];
	// repo config classes by type
	protected $configClass = [
		'wp-svn' => 'SVNRepositoryConfig',
		'wp-zip' => 'ZipRepositoryConfig',
	];
	// commands that are relevant to this plugin
	// @todo: is this list comprehensive?
	protected $commands = [ 'install', 'require', 'update', 'show', 'search', 'init', 'create-project' ];

	/**
	 * Set up our repos.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		// store composer and io objects for reuse
		$this->composer = $composer;
		$this->io = $io;
		// the extra data from the composer.json
		$extra = $composer->getPackage()->getExtra();
		// drill down to only our options
		$this->extra = !empty( $extra[ self::extra_field ] ) ? $extra[ self::extra_field ] : [];

		// let's reflect for a moment
		// we need to access the InputInterface, which is a protected member of $io
		$reflection = new \ReflectionProperty( $io, 'input' );
		$reflection->setAccessible( true );
		$input = $reflection->getValue( $io );
		// if it is not a command we care about, bail early
		if ( !in_array( $command = $input->getArgument( 'command' ), $this->commands ) ) {
			return;
		}

		// these will be all the repos we try to enable
		$repos = [];
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

		// load all the auto-load repos for these commands
		// otherwise figure out which vendors are needed
		if ( !( $loadAll = in_array( $command, [ 'search', 'init' ] ) ) ) {
			// get the root requirements to pluck out the vendor names
			$rootRequires = array_merge( $composer->getPackage()->getRequires(), $composer->getPackage()->getDevRequires() );
			$neededVendors = [];
			foreach ( $rootRequires as $link ) {
				$neededVendors[ strstr( $link->getTarget(), '/', true ) ] = true;
			}
			// capture package names passed as CLI args
			if ( $input->hasArgument( 'packages' ) ) {
				$packages = $input->getArgument( 'packages' );
			} else if ( $input->hasArgument( 'package' ) ) {
				$packages = (array) ( $input->getArgument( 'package' ) ?: [] );
			} else {
				$packages = [];
			}
			// get the vendor(s) of the passed package names
			foreach ( $packages as $package ) {
				if ( preg_match( '/^([a-z][\w-]+)\\//i', $package, $matches ) ) {
					$neededVendors[ strtolower( $matches[1] ) ] = true;
				}
			}
		}

		// get the configs for all the builtin repos and add vendor aliases
		$builtin = [];
		foreach ( $this->builtinRepos as $name => $class ) {
			// add the fully qualified namespace to the class name
			$class = '\\' . __NAMESPACE__ . '\\Repository\\Config\\Builtin\\' . $class;
			// instantiate the config class
			$builtin[ $name ] = new $class();
			// resolve aliases, disabled vendors, etc.
			$this->resolveVendors( $builtin[ $name ] );
			// check if the vendor is represented in the root composer.json
			// and enable this repo if it is one of the auto-load ones
			if ( in_array( $name, $this->autoLoadRepos ) && ( $loadAll || count( array_intersect_key( $builtin[ $name ]->get( 'vendors' ), $neededVendors ) ) ) ) {
				// this ensures that a repo is not enabled if it has been explicitly disabled by the user
				$repos += [ $name => true ];
			}
		}

		// the repo manager
		$rm = $this->composer->getRepositoryManager();
		// add our repo classes as available ones to use
		$rm->setRepositoryClass( 'wp-svn', '\\' . __NAMESPACE__ . '\\Repository\\SVNRepository' );
		$rm->setRepositoryClass( 'wp-zip', '\\' . __NAMESPACE__ . '\\Repository\\ZipRepository' );

		// create the repos!
		foreach ( $repos as $name => $definition ) {
			// is this a builtin repo?
			if ( isset( $builtin[ $name ] ) ) {
				// a falsey value means we will not use this repo
				if ( !$definition ) {
					continue;
				}
				$repoConfig = $builtin[ $name ];
				// allow config properties to be overridden by composer.json
				if ( is_array( $definition ) ) {
					// replace out these values
					foreach ( $definition as $key => $value ) {
						$repoConfig->set( $key, $value );
					}
				}
			} else {
				if ( !is_array( $definition ) ) {
					throw new \UnexpectedValueException( 'Custom repository definition must be a JSON object.' );
				}
				if ( !isset( $definition['type'] ) ) {
					throw new \UnexpectedValueException( 'Custom repository definition must declare a type.' );
				}
				if ( !isset( $this->configClass[ $definition['type'] ] ) ) {
					throw new \UnexpectedValueException( 'Unknown repository type ' . $definition['type'] );
				}
				$configClass = '\\' . __NAMESPACE__ . '\\Repository\\Config\\' . $this->configClass[ $definition['type'] ];
				$repoConfig = new $configClass( $definition );
				$this->resolveVendors( $repoConfig );
			}
			// provide the config some useful objects
			$repoConfig->setComposer( $this->composer );
			$repoConfig->setIO( $this->io );
			$repoConfig->setPlugin( $this );
			// add the repo!
			$rm->addRepository( $repo = $rm->createRepository( $repoConfig->getRepositoryType(), $repoConfig ) );
			$repoConfig->setRepo( $repo );
		}
	}

	/**
	 * Take a repo config and add/remove vendors based on extra.
	 * @param  RepositoryConfigInterface  $repoConfig
	 */
	protected function resolveVendors( RepositoryConfigInterface $repoConfig ) {
		static $filterDisable, $vendorAliases, $vendorDisable;
		// grab the vendor info from extra (only the first time)
		if ( !isset( $filterDisable, $vendorAliases, $vendorDisable ) ) {
			// get the user-defined mapping of vendor aliases
			$vendorAliases = !empty( $this->extra['vendors'] ) ? $this->extra['vendors'] : [];
			// get all of the keys that point to a falsey value - these vendors will be disabled
			$vendorDisable = array_keys( $vendorAliases, false );
			// now remove those falsey values
			$vendorAliases = array_filter( $vendorAliases );
			// a filter used to remove disabled vendors from an array of vendors
			$filterDisable = function( $value ) use ( $vendorDisable ) {
				return !in_array( $value, $vendorDisable );
			};
		}
		$types = $repoConfig->get( 'package-types' ) ?: [];
		// all vendors this repo recognizes, keyed on vendor
		$allVendors = [];
		// get the factory-set types
		// add user-defined vendor aliases
		foreach ( $types as $type => &$vendors ) {
			// vendors could be a string
			$vendors = (array) $vendors;
			// get the vendor aliases for any vendors that match those in this type
			$aliases = array_keys( array_intersect( $vendorAliases, $vendors ) );
			// combine, and remove duplicates and disabled aliases
			$vendors = array_filter( array_unique( array_merge( $vendors, $aliases ) ), $filterDisable );
			// add the recognized vendors for this type for handy retrieval
			$allVendors += array_fill_keys( $vendors, $type );
		}
		$repoConfig->set( 'package-types', $types );
		$repoConfig->set( 'vendors', $allVendors );
	}

	/**
	 * Instruct the plugin manager to subscribe us to these events.
	 */
	public static function getSubscribedEvents() {
		return [
			PluginEvents::PRE_FILE_DOWNLOAD => [
				[ 'setRfs', 0 ],
			],
		];
	}

	/**
	 * Set the remote filesystem for scp files.
	 */
	public function setRfs( PreFileDownloadEvent $event ) {
		static $rfs;
		// is this an ssh url?
		if ( strpos( $event->getProcessedUrl(), 'ssh://' ) === 0 ) {
			if ( !$rfs ) {
				$rfs = new SSHFilesystem( $this->io );
			}
			$event->setRemoteFilesystem( $rfs );
		}
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

	/**
	 * Get the IO object.
	 * @return IOInterface
	 */
	public function getIO() {
		return $this->io;
	}

}
