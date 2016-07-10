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

	const wp_content = 'wp-content'; // default wp-content path
	const wordpress = 'wp'; // default wordpress path
	protected $installInfo;

	function __construct( IOInterface $io, Composer $composer, array $installInfo ) {
		// fill in install info defaults
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
		// wp content path - either set or default
		$wpContent = $installInfo['wp-content-path'] ?: self::wp_content;
		// default paths for plugins and themes
		$installInfo['default-paths'] = [
			'wordpress-plugin' => "$wpContent/plugins",
			'wordpress-muplugin' => "$wpContent/mu-plugins",
			'wordpress-theme' => "$wpContent/themes",
		];
		// if the wp-content path was explicitly set, add to the path mapping for plugins/themes
		if ( $installInfo['wp-content-path'] ) {
			$installInfo['path-mapping'] += $installInfo['default-paths'];
		} else {
			// set the default wp-content path for mapping and symlinking
			$installInfo['wp-content-path'] = $wpContent;
		}
		// add a mapping for core if wordpress path is set
		if ( $installInfo['wordpress-path'] ) {
			$installInfo['path-mapping']['wordpress-core'] = $installInfo['wordpress-path'];
		} else {
			$installInfo['wordpress-path'] = $installInfo['default-paths']['wordpress-core'] = self::wordpress;
		}
		// wpmu-plugin-dir supersedes the default wp-content based path
		if ( $installInfo['wpmu-plugin-dir'] ) {
			$installInfo['path-mapping']['wordpress-muplugin'] = $installInfo['wpmu-plugin-dir'];
		} else {
			$installInfo['wpmu-plugin-dir'] = $installInfo['default-paths']['wordpress-muplugin'];
		}
		$this->installInfo = $installInfo;
		parent::__construct( $io, $composer );
	}

	/**
	 * Do we handle this package type? ;)
	 * Returns true for any package this installer can handle.
	 */
	function supports( $packageType ) {
		// need a path either in the path mapping or default path mapping
		return ( !empty( $this->installInfo['path-mapping'][ $packageType ] ) ||
			!empty( $this->installInfo['default-paths'][ $packageType ] ) )
			// if the path mapping is explicitly set to false, we do not support this type
			&& ( !isset( $this->installInfo['path-mapping'][ $packageType ] )
				|| $this->installInfo['path-mapping'][ $packageType ] !== false );
	}

	/**
	 * Returns true only if the path for this type has been explicitly set.
	 */
	function prioritySupports( $packageType ) {
		return !empty( $this->installInfo['path-mapping'][ $packageType ] );
	}

	function getInstallPath( PackageInterface $package ) {
		$type = $package->getType();
		// get the path from the mapping or the defaults
		if ( !empty( $this->installInfo['path-mapping'][ $type ] ) ) {
			$path = $this->installInfo['path-mapping'][ $type ];
		} else {
			$path = $this->installInfo['default-paths'][ $type ];
		}
		// only add the slug for themes and plugins
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

	/**
	 * Set an explicit path mapping.
	 * @param string $packageType package type
	 * @param string $path        path - uses the default path if not passed
	 */
	function setPath( $packageType, $path = null ) {
		if ( !isset( $path ) && !empty( $this->installInfo['default-paths'][ $packageType ] ) ) {
			$path = $this->installInfo['default-paths'][ $packageType ];
		}
		$this->installInfo['path-mapping'][ $packageType ] = $path;
	}

}
