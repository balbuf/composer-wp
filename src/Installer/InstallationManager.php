<?php

/**
 * An installer manager to replace the default one that allows us to better
 * determine whether to let composer use our installer or another one.
 */

namespace BalBuf\ComposerWP\Installer;

use Composer\Installer\InstallerInterface;
use Composer\IO\IOInterface;

class InstallationManager extends \Composer\Installer\InstallationManager {

	protected $wpInstaller;
	protected $io;

	function __construct( InstallerInterface $wpInstaller, IOInterface $io ) {
		$this->wpInstaller = $wpInstaller;
		$this->io = $io;
	}

	function getInstaller( $packageType ) {
		// if we don't support this type at all, move along to the rest of the installers
		if ( !$this->wpInstaller->supports( $packageType ) ) {
			$this->io->write( "Composer-WP installer does not support $packageType", true, IOInterface::DEBUG );
			return parent::getInstaller( $packageType );
		}
		// if we explicitly support this type, return the WP installer
		if ( $this->wpInstaller->prioritySupports( $packageType ) ) {
			$this->io->write( "Composer-WP installer explicitly supports $packageType", true, IOInterface::DEBUG );
			return $this->wpInstaller;
		}
		// otherwise, check to see if there is another installer that supports this type before we claim it
		try {
			// this may throw an InvalidArgumentException if no installer is found, but
			// LibraryInstaller is a floozie and will take any package it can get its hands on
			$installer = parent::getInstaller( $packageType );
			$class = get_class( $installer );
			// is the selected installer just the default one?
			if ( $class === 'Composer\\Installer\\LibraryInstaller' ) {
				throw new \InvalidArgumentException;
			}
			$this->io->write( "Composer-WP installer supports $packageType but gives priority to $class", true, IOInterface::DEBUG );
			// set the path to false so we do not try again
			$this->wpInstaller->setPath( $packageType, false );
			return $installer;
		} catch ( \InvalidArgumentException $e ) {
			$this->io->write( "Composer-WP installer claims support for $packageType", true, IOInterface::DEBUG );
			// no custom installer, let's take charge!
			$this->wpInstaller->setPath( $packageType );
			return $this->wpInstaller;
		}
	}

}
