<?php

/**
 * Custom installer for wordpress core, plugins, and themes.
 */

namespace BalBuf\ComposerWP\Installer;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class WordPressInstaller extends LibraryInstaller {

	protected $installInfo;
	protected $wpTypes;

	function __construct( IOInterface $io, Composer $composer, array $installInfo ) {
		$this->installInfo = $installInfo;
		// this is guaranteed to be set either by user or default value
		$wpContent = $installInfo['wp-content-path'];
		// accepted types and default paths
		$this->wpTypes = [
			'wordpress-core' => $installInfo['wordpress-path'],
			'wordpress-plugin' => "$wpContent/plugins",
			'wordpress-muplugin' => "$wpContent/mu-plugins",
			'wordpress-theme' => "$wpContent/themes",
		];
		parent::__construct( $io, $composer );
	}

	/**
	 * Do we handle this package type? ;)
	 */
	function supports( $packageType ) {
		static $checking = false;
		// bail if we are checking if another installer is available (to avoid infinite recursion)
		// or if this isn't a type we handle
		if ( $checking || !isset( $this->wpTypes[ $packageType ] ) ) {
			return false;
		}
		// do we have a path set already?
		if ( !empty( $this->installInfo['path-mapping'][ $packageType ] ) ) {
			return true;
		}
		// otherwise, let's check to see if there are any other installers that will handle this type
		$checking = true;
		try {
			// this may throw an InvalidArgumentException if no installer is found, but
			// LibraryInstaller is a floozie and will take any package it can get its hands on
			$installer = $this->composer->getInstallationManager()->getInstaller( $packageType );
			// is the selected installer just the default one?
			if ( get_class( $installer ) === 'Composer\Installer\LibraryInstaller' ) {
				throw new \InvalidArgumentException;
			}
		} catch ( \InvalidArgumentException $e ) {
			// no custom installer, let's take charge!
			$this->installInfo['path-mapping'][ $packageType ] = $this->wpTypes[ $packageType ];
			return !( $checking = false );
		}
		return ( $checking = false );
	}

	function getInstallPath( PackageInterface $package ) {
		$type = $package->getType();
		$path = $this->installInfo['path-mapping'][ $type ];
		if ( $type !== 'wordpress-core' ) {
			list(, $slug ) = explode( '/', $package->getPrettyName() );
			$path .= "/$slug";
		}
		return $path;
	}

	function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package ) {
		$repo->removePackage( $package );
		$installPath = $this->getInstallPath( $package );
		$this->io->write( sprintf( 'Deleting %s - %s', $installPath, $this->filesystem->removeDirectory( $installPath ) ? '<comment>deleted</comment>' : '<error>not deleted</error>' ) );
	}

	function getInstallInfo() {
		return $this->installInfo;
	}

	function getFilesystem() {
		return $this->filesystem;
	}

}
