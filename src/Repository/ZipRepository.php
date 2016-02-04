<?php

/**
 * Repository that points to a directory (local or remote) that contains zipped themes/plugins.
 */

namespace BalBuf\ComposerWP\Repository;

use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use BalBuf\ComposerWP\Repository\Config\ZipRepositoryConfig;
use Composer\IO\IOInterface;
use BalBuf\ComposerWP\Util\Util;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\ProcessExecutor;

class ZipRepository extends ArrayRepository implements ConfigurableRepositoryInterface {

	protected $loader;
	protected $repoConfig;
	protected $io;
	// are we using ssh?
	protected $ssh = false;

	function __construct( ZipRepositoryConfig $repoConfig, IOInterface $io ) {
		// check for some needed commands
		if ( !Util::cmdExists( 'find' ) ) {
			throw new \RuntimeException( 'The WP Zip repository requires the `find` command' );
		}
		if ( !Util::cmdExists( 'zipgrep' ) ) {
			throw new \RuntimeException( 'The WP Zip repository requires the `zipgrep` command' );
		}
		// get the config array
		$repoConfig = $repoConfig->getConfig();
		// are we using ssh?
		if ( $repoConfig['ssh'] ) {
			if ( !Util::cmdExists( 'ssh' ) ) {
				throw new \RuntimeException( 'The WP Zip repository requires the `ssh` command for remote repositories' );
			}
			if ( !Util::cmdExists( 'scp' ) ) {
				throw new \RuntimeException( 'The WP Zip repository requires the `scp` command for remote repositories' );
			}
			$this->ssh = true;
		}

		// @todo URL validation

		$this->io = $io;
		$this->loader = new ArrayLoader();
		$this->repoConfig = $repoConfig;
	}

	function getRepoConfig() {
		return $this->repoConfig;
	}

	protected function initialize() {
		parent::initialize();
		$this->scanDir();
	}

	/**
	 * Scan the directory set in $repoConfig['url']
	 * and create any found packages.
	 */
	protected function scanDir() {
		$dir = $this->repoConfig['url'];
		// make sure tilde is not escaped so it can be expanded
		// this allows '~/' followed by a path or just '~'
		if ( ( $tilde = substr( $dir, 0, 2 ) ) === '~/' || $tilde === '~' ) {
			$dir = $tilde . ProcessExecutor::escape( substr( $dir, strlen( $tilde ) ) );
		} else {
			$dir = ProcessExecutor::escape( $dir );
		}
		// patterns specific to both plugins and themes
		// 'inflating' is a line printed by unzip which indicates which internal file we are looking at
		$patterns = array( 'inflating|Version|Description|Author|Author URI|License' );
		// files within the archives to look at
		$files = array();
		// look for plugins?
		if ( isset( $this->repoConfig['package-types']['wordpress-plugin'] ) || isset( $this->repoConfig['package-types']['wordpress-muplugin'] ) ) {
			$patterns[] = 'Plugin Name|Plugin URI';
			$files[] = "'*.php'";
		}
		// look for themes?
		if ( isset( $this->repoConfig['package-types']['wordpress-theme'] ) ) {
			$patterns[] = 'Theme Name|Theme URI';
			$files[] = "'style.css'";
		}
		// determine if we have a depth limit
		$maxdepth = ( $depth = (int) $this->repoConfig['max-depth'] ) > 0 ? "-maxdepth $depth" : '';
		// assemble the command
		// 1. `find` to get all zip files in the given directory
		// 2. echo the filename so we can capture where the zip is
		// 3. use `unzip` paired with `grep` to scan the zip for WP
		//    theme or plugin headers in style.css or *.php files,
		//    respectively, but only in the top two directories within the zip
		$cmd = "find -L $dir $maxdepth -iname '*.zip' -exec echo '{}' ';' -exec sh -c "
			. "\"unzip -c {} '*.php' -x '*/*/*' | grep -iE '^[ ^I*]*(" . implode( '|', $patterns ) . ")'\" ';'";

		// if this is using ssh, wrap the command in an ssh call instead
		if ( $this->ssh ) {
			$cmd = 'ssh ' . ProcessExecutor::escape( $this->repoConfig['ssh'] ) . ' ' . ProcessExecutor::escape( $cmd );
		}

		$process = new ProcessExecutor( $this->io );

		// execute the command and see if the response code indicates success
		if ( ( $code = $process->execute( $cmd, $output ) ) === 0 ) {
			// store details about each of the files, which may be used to create a package
			$files = array();
			$zipFile = null;
			$fileName = null;
			// parse the response line-by-line to pluck out the header information
			foreach ( $process->splitLines( $output ) as $line ) {
				// is this a new zip file?
				if ( strtolower( substr( $line, -4 ) ) === '.zip' ) {
					$zipFile = $line;
				// is this a new internal file?
				} else if ( preg_match( '/^\s*inflating:\s*(.+?)\s*$/i', $line, $matches ) ) {
					$fileName = $matches[1];
				} else {
					// parse the line for information
					if ( preg_match( '/^[\s*]*([^:]+):\s*(.+?)\s*$/i', $line, $matches ) ) {
						// for clarity
						list(, $property, $value ) = $matches;
						$files[ $zipFile ][ $fileName ][ $property ] = $value;
					}
				}
			}
			// take the header information and create packages!
			foreach ( $files as $url => $packages ) {
				// we will only consider zips that have one package inside
				if ( count( $packages ) === 1 ) {
					// make sure all the keys are consistent
					$headers = array_change_key_case( reset( $packages ), CASE_LOWER );
					// file within the zip where the headers were found
					$fileName = key( $packages );
					// the info used to create the package
					$package = array();

					// we have a theme!
					if ( !empty( $headers['theme name'] ) ) {
						$package['type'] = 'wordpress-theme';
						$name = Util::slugify( $headers['theme name'] );
						$name = Util::callFilter( $this->repoConfig['name-filter'], $name, $url, $fileName, $headers );
						if ( !empty( $headers['theme uri'] ) ) {
							$package['homepage'] = $headers['theme uri'];
						}
					// we have a plugin!
					} else if ( !empty( $headers['plugin name'] ) ) {
						$package['type'] = 'wordpress-plugin';
						// use the basename of the file where the plugin headers were as the name
						// this is a wordpress convention, but may not always be accurate
						// @todo: what do we do about that?
						$name = Util::callFilter( $this->repoConfig['name-filter'], basename( $fileName, '.php' ), $url, $fileName, $headers );
						if ( !empty( $headers['plugin uri'] ) ) {
							$package['homepage'] = $headers['plugin uri'];
						}
					// does not appear to be a theme or plugin
					// sometimes other files get picked up
					} else {
						if ( $this->io->isVerbose() ) {
							$this->io->writeError( "$url does not appear to contain a valid package" );
						}
						continue;
					}

					// if the name is empty we don't use it
					if ( !strlen( $name ) ) {
						continue;
					}
					// add version
					if ( !empty( $headers['version'] ) ) {
						$package['version'] = Util::fixVersion( $headers['version'], 'dev-default' );
					} else {
						$package['version'] = 'dev-default';
					}
					$package['version'] = Util::callFilter( $this->repoConfig['version-filter'],
						$package['version'], $name, $url, $fileName, $headers );
					// empty version means we don't use it
					if ( !strlen( $package['version'] ) ) {
						continue;
					}
					// add author information
					if ( !empty( $headers['author'] ) ) {
						$package['authors'][0]['name'] = $headers['author'];
						if ( !empty( $headers['author uri'] ) ) {
							$package['authors'][0]['homepage'] = $headers['author uri'];
						}
					}
					// add description
					if ( !empty( $headers['description'] ) ) {
						$package['description'] = strip_tags( $headers['description'] );
					}
					// add license
					if ( !empty( $headers['license'] ) ) {
						$package['license'] = $headers['license'];
					}
					// add dist information
					// @todo: how do we handle ssh?
					$package['dist'] = array(
						'url' => $url,
						'type' => 'zip',
					);

					// add a new package for each vendor alias of the given type
					// @todo: maybe use links instead? or in addition to?
					foreach ( $this->repoConfig['vendors'] as $vendor => $type ) {
						// match wordpress-plugin for wordpress-muplugin vendors
						if ( $type === $package['type'] || ( $type === 'wordpress-muplugin' && $package['type'] === 'wordpress-plugin' ) ) {
							// this makes sure muplugins are the correct type
							$package['type'] = $type;
							$package['name'] = "$vendor/$name";
							$packageObj = $this->loader->load( $package );
							Util::callFilter( $this->repoConfig['package-filter'], $packageObj, $url, $fileName, $headers );
							$this->addPackage( $packageObj );
						}
					}
				} else {
					// if the zip contains multiple packages, we can't use it
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( "Cannot use file $url as is appears to contain multiple packages." );
					}
				}
			}
		} else {
			// some sort of error - boo!
			throw new \RuntimeException( 'Could not complete directory scan of ' . $this->repoConfig['url'] . '. ' . $process->getErrorOutput() );
		}
	}

}
