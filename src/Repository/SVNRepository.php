<?php

namespace BalBuf\ComposerWP\Repository;

use Composer\Repository\ComposerRepository;
use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use BalBuf\ComposerWP\Util\Svn as SvnUtil;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Pool;
use Composer\Semver\VersionParser;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;

/**
 * Mimicking a composer repository that has providers.
 *
 * A "provider" is the dependency name that is requested,
 * while a "package" is a specific version of that dependency.
 *
 * Provider names are stored without the vendor portion,
 * as the vendors are "virtual" and used to dictate what
 * type the package will be.
 */
class SVNRepository extends ComposerRepository {

	static protected $SvnUtil;
	protected $providerHash; // map {provider name} => {provider url}
	protected $distUrl;
	protected $plugin; //the plugin class that instantiated this repository
	protected $defaultVendor;

	public function __construct( SVNRepositoryConfig $repoConfig, IOInterface $io ) {
		// @TODO: add event dispatcher?
		$repoConfig = $repoConfig->getConfig();
		// check url immediately - can't do anything without it
		$urls = array();
		foreach ( (array) $repoConfig['url'] as $url ) {
			if ( ( $urlParts = parse_url( $url ) ) === false || empty( $urlParts['scheme'] ) ) {
				continue;
			}
			// untrailingslashit
			$urls[] = rtrim( $url, '/' );
		}
		if ( !count( $urls ) ) {
			throw new \UnexpectedValueException( 'No valid URLs for SVN repository: ' . print_r( $repoConfig['url'], true ) );
		}
		$repoConfig['url'] = $urls;
		$this->repoConfig = $repoConfig;

		$this->io = $io;
		$this->loader = new ArrayLoader();
		$this->plugin = $repoConfig['plugin'];

		reset( $repoConfig['vendors'] );
		$this->defaultVendor = key( $repoConfig['vendors'] );

		// set the SvnUtil for all instantiated classes to use
		if ( !isset( self::$SvnUtil ) ) {
			self::$SvnUtil = new SvnUtil( $io );
		}
	}

	public function findPackage( $name, $constraint ) {
		// @todo - where are these used? needs fixing
		// get vendor and package name parts
		if ( count( $parts = explode( '/', $name ) ) === 2 ) {
			list( $vendor, $name ) = $parts;
			if ( isset( $this->repoConfig['vendors'][ $vendor ] ) ) {
				$package = parent::findPackage( $name, $constraint );
				return $package;
			}
		}
	}

	public function findPackages( $name, $constraint = null ) {
		// @todo - where are these used? needs fixing
		// get vendor and package name parts
		if ( count( $parts = explode( '/', $name ) ) === 2 ) {
			list( $vendor, $name ) = $parts;
			if ( isset( $this->repoConfig['vendors'][ $vendor ] ) ) {
				$packages = parent::findPackages( $name, $constraint );
				return $packages;
			}
		}
		return array();
	}

	/**
	 * Get an array of provider names for this repository.
	 * @return array provider names
	 */
	public function getProviderNames() {
		$this->loadProviders();
		return $this->providerListing;
	}

	/**
	 * We have providers.
	 * @return boolean true
	 */
	public function hasProviders() {
		return true;
	}

	//protected function configurePackageWithVendorTransportOptions(); //@TODO come back to this

	public function whatProvides( Pool $pool, $name ) {
		// split on vendor and name
		if ( count( $parts = explode( '/', $name ) ) !== 2 ) {
			return array();
		}
		list( $vendor, $shortName ) = $parts;

		// does the vendor match one of our virtual vendors?
		if ( !isset( $this->repoConfig['vendors'][ $vendor ] ) ) {
			return array();
		}

		// do we already have its packages?
		if ( isset( $this->providers[ $name ] ) ) {
			return $this->providers[ $name ];
		}

		// make sure the providers have been loaded
		$this->loadProviders();

		// does the shortname even exist in this repo?
		if ( !isset( $this->providerHash[ $shortName ] ) ) {
			return array();
		}

		// base url for the requested set of packages (i.e. the provider)
		// there should be no trailing slash
		$providerUrl = $this->providerHash[ $shortName ];
		$packages = array();

		// get a listing of available packages
		// these are paths under the provider url where we should find actual packages
		foreach ( (array) $this->repoConfig['package-paths'] as $path ) {
			// the relative path without surrounding slashes
			$relPath = trim( $path, '/' );
			// if the path ends with a slash, we grab its subdirectories
			if ( substr( $path, -1 ) === '/' ) {
				// try to fetch the packages!
				try {
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( "Fetching available versions for $name" );
					}
					$pkgRaw = self::$SvnUtil->execute( 'ls', "$providerUrl/$relPath" );
				} catch( \RuntimeException $e ) {
					// @todo maybe don't throw an exception and just pass this one up?
					throw new \RuntimeException( "SVN Error: Could not retrieve package listing for $name. " . $e->getMessage() );
				}
				// check the versions and add any good ones to the set
				foreach ( SvnUtil::parseSvnList( $pkgRaw ) as $version ) {
					// format the version identifier to be composer-compatible
					$version = $this->filterVersion( $version, $name, $path, $providerUrl );
					// if the version string is empty, we don't take it
					if ( strlen( $version ) ) {
						$packages[ $version ] = trim( "$relPath/$version", '/' );
					}
				}
			} else {
				// otherwise we add as-is (no checking is performed to see if this reference really exists)
				// @todo: perhaps add an optional check?
				$version = $this->filterVersion( basename( $path ), $name, $path, $providerUrl );
				// if the version string is empty, we don't take it
				if ( strlen( $version ) ) {
					$packages[ $version ] = $relPath;
				}
			}
		}

		// store the providers based on its full name (i.e. with vendor)
		// this allows the same package to be loaded as different types,
		// which allows the package type to be changed in composer.json,
		// i.e. the type that is being removed AND the type that is being installed
		// both have to exist during the solving
		$this->providers[ $name ] = array();

		// create a package for each tag
		foreach ( $packages as $version => $reference ) {
			if ( !$pool->isPackageAcceptable( $shortName, VersionParser::parseStability( $version ) ) ) {
				continue;
			}
			// first, setup the repo-determined package properties
			$data = array(
				'name' => $name,
				'version' => $version,
				'type' => $this->repoConfig['vendors'][ $vendor ],
				'source' => array(
					'type' => 'svn',
					'url' => "$providerUrl/",
					// the reference cannot be empty or composer will throw an exception
					// an empty reference and a single slash are effectively the same
					'reference' => $reference ?: '/',
				),
				'require' => array(
					'oomphinc/composer-installers-extender' => '~1.0',
				),
			);
			// next, fill in any defaults that were missing
			if ( !empty( $this->repoConfig['package-defaults'] ) ) {
				$data = array_merge( $this->repoConfig['package-defaults'], $data );
			}
			// finally, apply any overrides
			if ( !empty( $this->repoConfig['package-defaults'] ) ) {
				$data = array_replace( $data, $this->repoConfig['package-defaults'] );
			}
			// create the package object
			$package = $this->createPackage( $data, 'Composer\Package\CompletePackage' );
			$package->setRepository( $this );
			// add "replaces" array for any other vendors that this repository supports
			if ( count( $this->repoConfig['vendors'] ) > 1 ) {
				$replaces = array();
				$constraint = new Constraint( '=', $package->getVersion() );
				foreach ( $this->repoConfig['vendors'] as $vendorName => $type ) {
					// it doesn't replace itself
					if ( $vendorName === $vendor ) {
						continue;
					}
					$replaces[] = new Link( $package->getName(), "$vendorName/$shortName", $constraint, "'$type' alias for", $package->getPrettyVersion() );
				}
				$package->setReplaces( $replaces );
			}
			// apply a filter to the package object
			if ( is_callable( $this->repoConfig['package-filter'] ) ) {
				call_user_func( $this->repoConfig['package-filter'], $package, $this->io, $this->plugin );
			}
			// add the package object to the set
			$this->providers[ $name ][ $version ] = $package;

			// handle root aliases
			// @todo: not sure if this is correct (this was copped from the parent class)
			if ( isset( $this->rootAliases[ $package->getName() ][ $package->getVersion() ] ) ) {
				$rootAliasData = $this->rootAliases[ $package->getName() ][ $package->getVersion() ];
				$alias = $this->createAliasPackage( $package, $rootAliasData['alias_normalized'], $rootAliasData['alias'] );
				$alias->setRepository( $this );
				$this->providers[ $name ][ $version . '-root' ] = $alias;
			}
		}

		return $this->providers[ $name ];

	}

	/**
	 * Run the version through the filter, if set.
	 */
	protected function filterVersion( $version, $name, $path ) {
		if ( is_callable( $this->repoConfig['version-filter'] ) ) {
			$version = call_user_func( $this->repoConfig['version-filter'], $version, $name, $path );
		}
		return $version;
	}

	/**
	 * Load the providers (i.e. package names) from the SVN repo.
	 */
	protected function loadProviders() {
		// maybe we already loaded them?
		if ( $this->providerListing !== null ) {
			return;
		}
		// start out empty
		$this->providerListing = $this->providerHash = array();

		// cycle through the urls
		foreach ( $this->repoConfig['url'] as $baseUrl ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( "Fetching providers from $baseUrl" );
			}
			// cycle through the provider path(s)
			foreach ( (array) $this->repoConfig['provider-paths'] as $path ) {
				// form the url to this provider listing - avoid double slashing and no trailing slash
				$url = rtrim( $baseUrl . '/' . ltrim( $path, '/' ), '/' );
				// if the path ends with a slash, we grab its subdirectories
				if ( substr( $path, -1 ) === '/' ) {
					// try to get a listing of providers
					try {
						$providersRaw = self::$SvnUtil->execute( 'ls', $url );
					} catch( \RuntimeException $e ) {
						throw new \RuntimeException( "SVN Error: Could not retrieve provider listing from $url " . $e->getMessage() );
					}
					// cycle through to remove exclusions
					foreach ( SvnUtil::parseSvnList( $providersRaw ) as $name ) {
						$this->addProvider( $name, $path, "$url/$name" );
					}
				} else {
					// otherwise we add as-is - the provider name is just the basename of the relative path
					// these explicit providers are not checked to see if they actually exist
					// @todo: optional check to exclude these if 404?
					$this->addProvider( basename( $path ), $path, $url );
				}
			}
		}
	}

	/**
	 * Add a provider to the set; call optional name filter.
	 * @param string $name    resolved provider name
	 * @param string $relPath the provider path from the repo config
	 * @param string $absUrl  the fully qualified URL to this provider
	 */
	protected function addProvider( $name, $relPath, $absUrl ) {
		// is there a provider name filter?
		if ( is_callable( $this->repoConfig['name-filter'] ) ) {
			$name = call_user_func( $this->repoConfig['name-filter'], $name, $relPath, $absUrl );
		}
		// only add the provider if it is truthy
		if ( $name ) {
			// this provider listing is not used in solving, just for listing
			// so just use the default vendor (i.e. first one we have)
			$this->providerListing[] = "{$this->defaultVendor}/$name";
			$this->providerHash[ $name ] = $absUrl;
		}
	}

	/**
	 * No-op
	 */
	function resetPackageIds() {}

	/**
	 * No-op
	 */
	function addPackage( PackageInterface $package ) {}

	/**
	 * No-op
	 */
	protected function loadRootServerFile() {}

}
