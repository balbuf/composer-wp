<?php

/**
 * Remote filesystem for downloading files via ssh.
 */

namespace BalBuf\ComposerWP\Util;

use Composer\Util\RemoteFilesystem;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;

class SSHFilesystem extends RemoteFilesystem {

	protected $io;
	protected $process;

	function __construct( IOInterface $io ) {
		$this->io = $io;
		$this->process = new ProcessExecutor( $io );
	}

	protected function get( $originUrl, $fileUrl, $options = [], $fileName = null, $progress = true ) {
		if ( strpos( $fileUrl, 'ssh://' ) !== 0 ) {
			throw new \UnexpectedValueException( "This does not appear to be a file that should be downloaded via ssh: $fileUrl" );
		}
		// strip off the pseudo protocol
		$fileUrl = substr( $fileUrl, 6 );
		if ( $this->io->isVerbose() ) {
			$this->io->write( "Downloading $fileUrl via ssh." );
		}
		// filename means we want to save
		if ( $fileName ) {
			$cmd = 'scp ' . ProcessExecutor::escape( $fileUrl ) . ' ' . ProcessExecutor::escape( $fileName );
		} else {
			// otherwise just return the file contents
			list( $host, $path ) = explode( ':', $fileUrl );
			$cmd = 'ssh ' . ProcessExecutor::escape( $host ) . ' ' . ProcessExecutor::escape( 'cat ' . ProcessExecutor::escape( $path ) );
		}
		if ( $progress ) {
			$this->io->writeError( '    Downloading: <comment>Connecting...</comment>', false );
		}
		// success?
		// @todo: do we need to catch any exceptions here?
		if ( $this->process->execute( $cmd, $output ) === 0 ) {
			if ( $progress ) {
				$this->io->overwriteError( '    Downloading: <comment>100%</comment>' );
			}
			return $output;
		} else {
			// some sort of error - boo!
			throw new \RuntimeException( "Could not download $fileUrl. " . $this->process->getErrorOutput() );
		}
	}

}
