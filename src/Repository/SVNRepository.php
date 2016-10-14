<?php

namespace BalBuf\ComposerWP\Repository;

use Composer\Repository\ComposerRepository;
use BalBuf\ComposerWP\Repository\Config\SVNRepositoryConfig;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Cache;
use Composer\Package\Loader\ArrayLoader;
use BalBuf\ComposerWP\Util\Svn as SvnUtil;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Pool;
use BalBuf\ComposerWP\Util\Util;
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

	protected $svnUtil;
	protected $providerHash; // map {provider name} => {provider url}
	protected $distUrl;
	protected $plugin; //the plugin class that instantiated this repository
	protected $vendors;
	protected $defaultVendor;

	public function __construct( SVNRepositoryConfig $repoConfig, IOInterface $io, Config $config ) {
		// @TODO: add event dispatcher?
		$this->repoConfig = $repoConfig;
		$this->plugin = $repoConfig->getPlugin();
		// check url immediately - can't do anything without it
		$urls = [];
		foreach ( (array) $repoConfig->get( 'url' ) as $url ) {
			if ( ( $urlParts = parse_url( $url ) ) === false || empty( $urlParts['scheme'] ) ) {
				continue;
			}
			// untrailingslashit
			$urls[] = rtrim( $url, '/' );
		}
		if ( !count( $urls ) ) {
			throw new \UnexpectedValueException( 'No valid URLs for SVN repository: ' . print_r( $repoConfig->get( 'url' ), true ) );
		}
		$repoConfig->set( 'url', $urls );
		// use the cache TTL from the config?
		if ( $repoConfig->get( 'cache-ttl' ) === 'config' ) {
			$repoConfig->set( 'cache-ttl', $config->get( 'cache-files-ttl' ) );
		}

		$this->io = $io;
		$this->cache = new Cache( $io, $config->get( 'cache-repo-dir' ) . '/' . preg_replace( '{[^a-z0-9.]}i', '-', reset( $urls ) ) );
		$this->loader = new ArrayLoader();

		// clear out stale cache
		$this->cache->gc( $repoConfig->get( 'cache-ttl' ), $config->get( 'cache-files-maxsize' ) );

		$this->vendors = $repoConfig->get( 'vendors' );
		$this->defaultVendor = key( $this->vendors );

		// create an SvnUtil to execute commands
		$this->svnUtil = new SvnUtil( $io, $repoConfig->get( 'trust-cert' ) );
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
		return [];
	}

	public function search( $query, $mode = 0 ) {
		// if the query exactly matches one of our vendors, return the whole list!
		if ( isset( $this->vendors[ $query ] ) ) {
			// make sure the vendor that shows is the vendor they want
			$this->defaultVendor = $query;
			$out = [];
			foreach ( $this->getProviderNames() as $provider ) {
				$out[] = [ 'name' => $provider ];
			}
			return $out;
		}
		// try running the search handler - see if we get anything
		$results = Util::callFilter( $this->repoConfig->get( 'search-handler' ), $query );
		// if the these are the same, default to the normal provider name search
		if ( $results === $query ) {
			return parent::search( $query, $mode );
		}
		return $results;
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

	public function whatProvides( Pool $pool, $name, $bypassFilters = false ) {
		// split on vendor and name
		if ( count( $parts = explode( '/', $name ) ) !== 2 ) {
			return [];
		}
		list( $vendor, $shortName ) = $parts;

		// does the vendor match one of our virtual vendors?
		if ( !isset( $this->vendors[ $vendor ] ) ) {
			return [];
		}

		// do we already have its packages?
		if ( isset( $this->providers[ $name ] ) ) {
			return $this->providers[ $name ];
		}

		// make sure the providers have been loaded
		$this->loadProviders();

		// does the shortname even exist in this repo?
		if ( !isset( $this->providerHash[ $shortName ] ) ) {
			return [];
		}

		// base url for the requested set of packages (i.e. the provider)
		// there should be no trailing slash
		$providerUrl = $this->providerHash[ $shortName ];
		$packages = [];

		// get a listing of available packages
		// these are paths under the provider url where we should find actual packages
		foreach ( (array) $this->repoConfig->get( 'package-paths' ) as $path ) {
			// the relative path without surrounding slashes
			$relPath = trim( $path, '/' );
			// if the path ends with a slash, we grab its subdirectories
			if ( substr( $path, -1 ) === '/' ) {
				// try to fetch the packages!
				try {
					if ( $this->io->isVerbose() ) {
						$this->io->writeError( "Fetching available versions for $name" );
					}
					$pkgRaw = $this->svnUtil->execute( 'ls', "$providerUrl/$relPath" );
				} catch( \RuntimeException $e ) {
					// @todo maybe don't throw an exception and just pass this one up?
					throw new \RuntimeException( "SVN Error: Could not retrieve package listing for $name. " . $e->getMessage() );
				}
				// check the versions and add any good ones to the set
				foreach ( SvnUtil::parseSvnList( $pkgRaw ) as $version ) {
					// format the version identifier to be composer-compatible
					$version = Util::fixVersion( $version, 'dev-default' );
					$version = Util::callFilter( $this->repoConfig->get( 'version-filter' ), $version, $name, $path, $providerUrl );
					// if the version string is empty, we don't take it
					if ( !empty( $version ) ) {
						$packages[ $version ] = trim( "$relPath/$version", '/' );
					}
				}
			} else {
				// otherwise we add as-is (no checking is performed to see if this reference really exists)
				// @todo: perhaps add an optional check?
				$version = Util::fixVersion( basename( $path ), 'dev-default' );
				$version = Util::callFilter( $this->repoConfig->get( 'version-filter' ), $version, $name, $path, $providerUrl );
				// if the version string is empty, we don't take it
				if ( !empty( $version ) ) {
					$packages[ $version ] = $relPath;
				}
			}
		}

		// store the providers based on its full name (i.e. with vendor)
		// this allows the same package to be loaded as different types,
		// which allows the package type to be changed in composer.json,
		// i.e. the type that is being removed AND the type that is being installed
		// both have to exist during the solving
		$this->providers[ $name ] = [];

		// create a package for each tag
		foreach ( $packages as $version => $reference ) {
			if ( !$pool->isPackageAcceptable( $shortName, VersionParser::parseStability( $version ) ) ) {
				continue;
			}
			// first, setup the repo-determined package properties
			$data = [
				'name' => $name,
				'version' => $version,
				'type' => $this->vendors[ $vendor ],
				'source' => [
					'type' => 'svn',
					'url' => "$providerUrl/",
					// the reference cannot be empty or composer will throw an exception
					// an empty reference and a single slash are effectively the same
					'reference' => $reference ?: '/',
				],
			];
			// next, fill in any defaults that were missing
			if ( ( $defaults = $this->repoConfig->get( 'package-defaults' ) ) && is_array( $defaults ) ) {
				$data = array_merge( $defaults, $data );
			}
			// finally, apply any overrides
			if ( ( $overrides = $this->repoConfig->get( 'package-overrides' ) ) && is_array( $overrides ) ) {
				$data = array_replace( $data, $overrides );
			}
			// create the package object
			$package = $this->createPackage( $data, 'Composer\Package\CompletePackage' );
			$package->setRepository( $this );
			// add "replaces" array for any other vendors that this repository supports
			if ( count( $this->vendors ) > 1 ) {
				$replaces = [];
				$constraint = new Constraint( '=', $package->getVersion() );
				foreach ( $this->vendors as $vendorName => $type ) {
					// it doesn't replace itself
					if ( $vendorName === $vendor ) {
						continue;
					}
					$replaces[] = new Link( $package->getName(), "$vendorName/$shortName", $constraint, "'$type' alias for", $package->getPrettyVersion() );
				}
				$package->setReplaces( $replaces );
			}
			// apply a filter to the package object
			Util::callFilter( $this->repoConfig->get( 'package-filter' ), $package );
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
	 * Load the providers (i.e. package names) from the SVN repo (or cache).
	 */
	protected function loadProviders() {
		// maybe we already loaded them?
		if ( $this->providerListing !== null ) {
			return;
		}
		// maybe we have our providers stashed in the cache
		$cacheFile = $this->repoConfig->get( 'cache-file' );
		$providerHash = ( $hash = $this->cache->sha256( $cacheFile ) ) ? json_decode( $this->cache->read( $cacheFile ), true ) : false;
		$providerHash = Util::callFilter( $this->repoConfig->get( 'cache-handler' ), $providerHash, $this->cache );
		// if provider hash is an array, we assume this is the complete set
		if ( is_array( $providerHash ) ) {
			$this->providerHash = $providerHash;
			$this->providerListing = [];
			// fill out the provider list
			foreach ( $providerHash as $name => $url ) {
				$this->providerListing[] = "{$this->defaultVendor}/$name";
			}
			goto save;
		}

		// otherwise we need to retrieve the providers
		// start out empty
		$this->providerListing = $this->providerHash = [];

		// cycle through the urls
		foreach ( $this->repoConfig->get( 'url' ) as $baseUrl ) {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( "Fetching providers from $baseUrl" );
			}
			// cycle through the provider path(s)
			foreach ( (array) $this->repoConfig->get( 'provider-paths' ) as $path ) {
				// form the url to this provider listing - avoid double slashing and no trailing slash
				$url = rtrim( $baseUrl . '/' . ltrim( $path, '/' ), '/' );
				// if the path ends with a slash, we grab its subdirectories
				if ( substr( $path, -1 ) === '/' ) {
					// try to get a listing of providers
					try {
						$providersRaw = $this->svnUtil->execute( 'ls', $url );
					} catch( \RuntimeException $e ) {
						throw new \RuntimeException( "SVN Error: Could not retrieve provider listing from $url " . $e->getMessage() );
					}
					// cycle through and add providers
					foreach ( SvnUtil::parseSvnList( $providersRaw ) as $name ) {
						// name, rel path, abs path
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
		save:
		// save to the cache if we have a TTL and the contents has changed (so that TTL is compared against the first time it was saved)
		if ( $this->repoConfig->get( 'cache-ttl' ) && $hash !== hash( 'sha256', $contents = json_encode( $this->providerHash ) ) ) {
			$this->cache->write( $cacheFile, $contents );
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
		$name = Util::callFilter( $this->repoConfig->get( 'name-filter' ), $name, $relPath, $absUrl );
		// only add the provider if it is truthy
		if ( $name ) {
			// this provider listing is not used in solving, just for listing
			// so just use the default vendor (i.e. first one we have)
			$this->providerListing[] = "{$this->defaultVendor}/$name";
			$this->providerHash[ $name ] = $absUrl;
		}
	}

	/**
	 * Useful for search handlers in returning results.
	 * @return string the default vendor to display in search listings
	 */
	function getDefaultVendor() {
		return $this->defaultVendor;
	}

	/**
	 * Get the cache for this repo.
	 * @return Cache
	 */
	function getCache() {
		return $this->cache;
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
