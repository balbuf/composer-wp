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
 * - create a script to scan the headers of every plugin to identify anomolies
 * - caching for SSH zip repos
 * - cache plugin/theme info for .org repos
 * - allow additional ssh options set via environment vars
 * - figure out what is going on with the findPackage(s) methods
 * - look into whether the 'replaces' packages are generating extraneous calls to the SVN repo
 * - add a check to see if there is a new version of the plugin available and provide notice if so
 * - envato repo type
 * - possibility of mu-plugins dir being moved
 * - drop ins?
 * - consider plugin hooks: installed, activated, deactivated, etc.
 * - option to disable normal plugin installation and updates?
 */

namespace BalBuf\ComposerWP;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use BalBuf\ComposerWP\Installer\WordPressInstaller;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Config;
use BalBuf\ComposerWP\Repository\Config\RepositoryConfigInterface;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;
use Composer\Plugin\PreFileDownloadEvent;
use BalBuf\ComposerWP\Util\SSHFilesystem;
use Composer\EventDispatcher\Event;


class Plugin implements PluginInterface, EventSubscriberInterface {

	// the plugin properties are defined in this 'extra' field
	const extra_field = 'composer-wp';
	const installer_field = 'installer';
	const repo_field = 'repositories';
	const vendor_field = 'vendors';
	protected $composer;
	protected $io;
	protected $extra;
	protected $installer;
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
		// set empty array as default to indicate we will use the installer with its defaults
		$this->extra += [ self::installer_field => [] ];

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
		if ( !empty( $this->extra[ self::repo_field ] ) ) {
			if ( !is_array( $this->extra[ self::repo_field ] ) ) {
				throw new \Exception( '[extra][' . self::extra_field . '][' . self::repo_field . '] should be an array of repository definitions.' );
			}
			foreach ( $this->extra[ self::repo_field ] as $repo ) {
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
			$vendorAliases = !empty( $this->extra[ self::vendor_field ] ) ? $this->extra[ self::vendor_field ] : [];
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
			PluginEvents::COMMAND => [
				[ 'setupInstaller', 0 ],
			],
			ScriptEvents::POST_INSTALL_CMD => [
				[ 'postInstall', 0 ],
			],
			ScriptEvents::POST_UPDATE_CMD => [
				[ 'postInstall', 0 ],
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
	 * Setup our custom installer, if enabled.
	 * We run this as late as possible so our installer takes precedence. Our installer is
	 * lazy and only handles packages if explicitly asked or no other custom installer exists
	 * for our package types.
	 */
	public function setupInstaller() {
		// make sure we only setup once
		static $setup = false;
		// if it isn't an array, it means the installer is disabled
		if ( $setup || !is_array( $installInfo = $this->extra[ self::installer_field ] ) ) {
			return;
		}
		// set the default install configuration
		$installInfo += [
			'wordpress-path' => false,
			'wp-content-path' => false,
			// set to specify a different dir
			'wpmu-plugin-dir' => false,
			// {type} => {install path} (package slug will be appended)
			'path-mapping' => [],
			// replace the wp-content folder in the wordpress path with a symlink to the composer wp-content dir?
			'symlink-wp-content' => true,
			// add an autoloader to the mu-plugins dir?
			'mu-plugin-autoloader' => true,
			// put the require-dev packages first in the autoloader order?
			'dev-first' => false,
		];
		// add a mapping for core if wordpress path is set
		if ( $installInfo['wordpress-path'] ) {
			$installInfo['path-mapping']['wordpress-core'] = $installInfo['wordpress-path'];
		} else {
			// default wordpress core install path
			$installInfo['wordpress-path'] = 'wp';
		}
		// add mappings for plugins and themes if wp content path is set
		if ( $wpContent = $installInfo['wp-content-path'] ) {
			$installInfo['path-mapping'] += [
				'wordpress-plugin' => "$wpContent/plugins",
				'wordpress-muplugin' => "$wpContent/mu-plugins",
				'wordpress-theme' => "$wpContent/themes",
			];
		} else {
			// set the default wp-content path for mapping and symlinking
			$installInfo['wp-content-path'] = 'wp-content';
		}
		// wpmu-plugin-dir supersedes the default wp-content based path
		if ( $installInfo['wpmu-plugin-dir'] ) {
			$installInfo['path-mapping']['wordpress-muplugin'] = $installInfo['wpmu-plugin-dir'];
		} else if ( !empty( $installInfo['wp-content-path'] ) ) {
			$installInfo['wpmu-plugin-dir'] = $installInfo['wp-content-path'] . '/mu-plugins';
		}
		$this->installer = new WordPressInstaller( $this->io, $this->composer, $installInfo );
		$this->composer->getInstallationManager()->addInstaller( $this->installer );
		$setup = true;
	}

	/**
	 * Take some additional actions after we have installed or updated packages.
	 */
	public function postInstall( Event $event ) {
		// bail if the installer is disabled
		if ( !( $installer = $this->getInstaller() ) ) {
			return;
		}
		$installInfo = $installer->getInstallInfo();
		$filesystem = $installer->getFilesystem();
		// replace the wp-content dir in wp with a symlink to the real wp-content folder
		if ( $installInfo['symlink-wp-content'] && $installInfo['wp-content-path'] && $installInfo['wordpress-path'] ) {
			$dir = getcwd() . '/' . $installInfo['wordpress-path'] . '/wp-content';
			// only do this if the directory exists and is not a symlink, and the wp-content dir exists
			if ( is_dir( $dir ) && !is_link( $dir ) && is_dir( $installInfo['wp-content-path'] ) ) {
				$dir = realpath( $dir );
				$filesystem->removeDirectory( $dir );
				$filesystem->relativeSymlink( realpath( $installInfo['wp-content-path'] ), $dir );
			}
		}
		// add the mu-plugins autoloader
		if ( $installInfo['mu-plugin-autoloader'] && $installInfo['wpmu-plugin-dir'] && is_dir( $installInfo['wpmu-plugin-dir'] ) ) {
			$muPluginsDir = realpath( $installInfo['wpmu-plugin-dir'] );
			// @todo - only copy this if it has changed?
			copy( __DIR__ . '/Installer/composer-wp-autoloader.php', "$muPluginsDir/composer-wp-autoloader.php" );
			// data which will be provided to the autoloader
			$packageData = [ 'packages' => [] ];
			$vendorDir = realpath( $this->composer->getConfig()->get( 'vendor-dir' ) );
			// make the composer autoloader reference relative to the WP core dir, if we have it
			if ( $installInfo['wordpress-path'] && is_dir( $installInfo['wordpress-path'] ) ) {
				$packageData['autoloader'] = [
					'path' => $filesystem->findShortestPath( realpath( $installInfo['wordpress-path'] ), $vendorDir, true ),
					'relativeTo' => 'ABSPATH',
				];
			} else {
				// otherwise make relative to the mu-plugins dir
				$packageData['autoloader'] = [
					'path' => $filesystem->findShortestPath( $muPluginsDir, $vendorDir, true ),
					'relativeTo' => 'WPMU_PLUGIN_DIR',
				];
			}
			$packageData['autoloader']['path'] .= '/autoload.php';
			// used for sorting
			$packageIndex = [];
			// do we want the dev packages loaded first?
			if ( $installInfo['dev-first'] ) {
				$requires = $this->composer->getPackage()->getDevRequires() + $this->composer->getPackage()->getRequires();
			} else {
				$requires = $this->composer->getPackage()->getRequires() + $this->composer->getPackage()->getDevRequires();
			}
			// take only the keys which are the package names
			$requires = array_keys( $requires );
			// get all of the installed packages
			$packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
			// find the mu-plugin packages
			foreach ( $packages as $package ) {
				if ( $package->getType() === 'wordpress-muplugin' ) {
					list(, $slug ) = explode( '/', $package->getPrettyName() );
					// try to identify the actual plugin file
					// @todo: can we find the install path based on the package??
					foreach( glob( "$muPluginsDir/$slug/*.php", \GLOB_NOSORT ) as $file ) {
						// use the same max byte length that WP does
						$header = file_get_contents( $file, false, null, 0, 8192 );
						// if it has a Plugin Name, that's our girl!
						if ( preg_match( '/^[\s*]*Plugin Name:.*$/im', $header ) ) {
							// store as the relative path
							$packageData['packages'][] = $slug . '/' . basename( $file );
							$packageIndex[] = array_search( $package->getPrettyName(), $requires );
							break;
						}
					}
				}
			}
			// sort the packages based on their position in require/require-dev
			array_multisort( $packageIndex, \SORT_NUMERIC, \SORT_ASC, $packageData['packages'] );
			// save the package data in the mu-plugins dir
			file_put_contents( "$muPluginsDir/composer-wp-packages.json", json_encode( $packageData ) );
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

	/**
	 * Get the installer object, if it was set.
	 * @return InstallerInterface
	 */
	public function getInstaller() {
		return $this->installer;
	}

}
