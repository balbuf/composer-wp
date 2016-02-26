<?php

/**
 * Custom installer for wordpress core, plugins, and themes.
 */

namespace BalBuf\ComposerWP;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class WordPressInstaller extends LibraryInstaller {

	protected $installInfo;

	function __construct( IOInterface $io, Composer $composer, array $installInfo ) {
		$this->installInfo = $installInfo;
		parent::__construct( $io, $composer );
	}

	/**
	 * Do we handle this package type? ;)
	 */
	function supports( $packageType ) {
		return isset( $this->installInfo['path-mapping'][ $packageType ] );
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
