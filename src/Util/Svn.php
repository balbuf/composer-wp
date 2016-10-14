<?php

namespace BalBuf\ComposerWP\Util;

use Composer\IO\IOInterface;
use Composer\Util\Svn as SvnUtil;
use Composer\Config;

/**
 * Light wrapper for \Composer\Util\Svn that provides
 * additional utilities.
 */
class Svn {

	protected $util;
	protected $trustCert;

	function __construct( IOInterface $io, $trustCert = false ) {
		$this->util = new SvnUtil( '', $io, new Config );
		$this->setCertTrusted( $trustCert );
	}

	/**
	 * Set whether we will trust the the SSL certificate automatically.
	 * @param bool $bool yas/nah
	 */
	function setCertTrusted( $bool ) {
		$this->trustCert = (bool) $bool;
	}

	/**
	 * Are we automatically trusting the cert?
	 * @return bool yas/nah
	 */
	function isCertTrusted() {
		return $this->trustCert;
	}

	/**
	 * Execute an svn command. May throw a \RuntimeException on error.
	 * @param  string $command svn command without `svn`
	 * @param  string $url     svn url to act upon
	 * @return string          response
	 */
	function execute( $command, $url ) {
		// is this an https connection, and do we trust it automatically?
		if ( strncasecmp( $url, 'https', 5 ) === 0 && $this->isCertTrusted() ) {
			// let svn trust the certificate without prompt
			$command .= ' --non-interactive --trust-server-cert';
		}
		return $this->util->execute( "svn $command", $url );
	}

	/**
	 * Take a full `svn ls` response and parse it into an array of items.
	 * @param  string $response response from svn
	 * @return array           items returned (could be empty)
	 */
	static function parseSvnList( $response ) {
		$list = [];
		// break on space, newline, or forward slash
		// leaving us with perfectly trimmed names
		$token = " \n\r/";
		$item = strtok( $response, $token );
		while ( $item !== false ) {
			$list[] = $item;
			$item = strtok( $token );
		}
		return $list;
	}
}
