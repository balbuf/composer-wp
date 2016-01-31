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

	function __construct( IOInterface $io ) {
		$this->util = new SvnUtil( '', $io, new Config );
	}

	/**
	 * Execute an svn command. May throw a \RuntimeException on error.
	 * @param  string $command svn command without `svn`
	 * @param  string $url     svn url to act upon
	 * @return string          response
	 */
	function execute( $command, $url ) {
		return $this->util->execute( "svn $command", $url );
	}

	/**
	 * Take a full `svn ls` response and parse it into an array of items.
	 * @param  string $response response from svn
	 * @return array           items returned (could be empty)
	 */
	static function parseSvnList( $response ) {
		$list = array();
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
