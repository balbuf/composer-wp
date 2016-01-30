<?php

namespace BalBuf\ComposerWP\Repository;

use Composer\Repository\ComposerRepository;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Svn as SvnUtil;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Pool;
use Composer\Semver\VersionParser;

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

	protected $providersHash; // key providers stored by key for quicker existence check
	protected $vendors = array(); // vendor name mapping to type
	protected $util; //svn command utility
	protected $distUrl;
	// config defaults
	protected $repoConfig = array(
		// base url
		'url' => null,
		// paths to specific providers or to listing of providers, relative to url
		// paths ending with a slash are considered a listing and will use `svn ls` to retrieve the providers
		// otherwise, the path is taken at face value to point to a specific provider
		// the provider name that is used will only be the basename, e.g. path/basename
		'provider-paths' => array( '/' ),
		// match provider names to exclude from the listing
		// does not apply to explicit provider paths (i.e. those not ending in a slash)
		// @see preg_match
		'provider-exclude' => null,
		// array of alias => actual mappings
		// actual should be full path of the provider relative to the base url
		'provider-aliases' => array(),
		// paths to specific packages or listing of packages within the providers
		// this is relative to the provider url
		'package-paths' => array( '/' ),
		// manipulate version identifiers to make them parsable by composer
		// if the version is replaced with an empty string, it will be excluded
		// all replacement patterns are executed in the order they are declared here
		// @see preg_replace
		'version-replace' => array(
			'/^trunk$/' => 'dev-trunk',
		),
		// mapping of supported package types to virtual vendor(s)
		// vendors can be a single string or an array of strings
		// the requested virtual vendor of the dependency will dictate the package's type
		'types' => array(),
		// array of package defaults that will be the basis for the package definition
		'package-defaults' => array(),
		// array of values to override any fields after defaults and repo-determined are resolved
		'package-overrides' => array(),
		// a function which is called on the package object after it is created and before used by the solver
		'package-filter' => null,
	);

	public function __construct( array $repoConfig, IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null ) {
		// @TODO: add event dispatcher?
		// check url immediately - can't do anything without it
		if ( empty( $repoConfig['url'] ) || ( $urlParts = parse_url( $repoConfig['url'] ) ) === false || empty( $urlParts['scheme'] ) ) {
			throw new \UnexpectedValueException( 'Invalid or missing url given for Wordpress SVN repository: ' . ( isset( $repoConfig['url'] ) ? $repoConfig['url'] : '' ) );
		}
		// untrailingslashit
		$repoConfig['url'] = rtrim( $repoConfig['url'], '/' );

		// start with the defaults and update any properties
		$this->repoConfig = array_replace( $this->repoConfig, $repoConfig );

		$this->io = $io;
		$this->loader = new ArrayLoader();
		// used for svn commands
		$this->util = new SvnUtil( '', $io, new Config );

		// parse and store the vendor / package type mappings
		if ( is_array( $this->repoConfig['types'] ) && count( $this->repoConfig['types'] ) ) {
			$this->vendors = array();
			// add the types
			foreach ( $this->repoConfig['types'] as $type => $vendors ) {
				// add the recognized vendors for this type
				foreach ( (array) $vendors as $vendor ) {
					$this->vendors[ $vendor ] = $type;
				}
			}
		} else {
			throw new \UnexpectedValueException( 'Vendor / package type mapping is required.' );
		}
	}

	public function findPackage( $name, $constraint ) {
		// @todo - where are these used? needs fixing
		// get vendor and package name parts
		if ( count( $parts = explode( '/', $name ) ) === 2 ) {
			list( $vendor, $name ) = $parts;
			if ( isset( $this->vendors[ $vendor ] ) ) {
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
			if ( isset( $this->vendors[ $vendor ] ) ) {
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
		if ( !isset( $this->vendors[ $vendor ] ) ) {
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
					$pkgRaw = $this->util->execute( 'svn ls', $providerUrl . $relPath );
				} catch( \RuntimeException $e ) {
					// @todo maybe don't throw an exception and just pass this one up?
					throw new \RuntimeException( "SVN Error: Could not retrieve package listing for $name. " . $e->getMessage() );
				}
				// check the versions and add any good ones to the set
				foreach ( $this->parseSvnList( $pkgRaw ) as $version ) {
					// format the version identifier to be composer-compatible
					$version = $this->replaceVersion( $version );
					// if the version string is empty, we don't take it
					if ( strlen( $version ) ) {
						$packages[ $version ] = $relPath . "/$version";
					}
				}
			} else {
				// otherwise we add as-is (no checking is performed to see if this reference really exists)
				// @todo: perhaps add an optional check?
				$version = $this->replaceVersion( basename( $path ) );
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
				'type' => $this->vendors[ $vendor ],
				'source' => array(
					'type' => 'svn',
					'url' => $providerUrl,
					'reference' => $reference,
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
			$package = $this->createPackage( $data, 'Composer\Package\CompletePackage' );
			$package->setRepository( $this );
			// apply a filter to the package object
			if ( is_callable( $this->repoConfig['package-filter'] ) ) {
				call_user_func( $this->repoConfig['package-filter'], $package );
			}
			// add the package object to the set
			$this->providers[ $name ][ $version ] = $package;

			// handle root aliases - not sure if they will apply here (this was copped from the parent class)
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
	 * Run the version through the replacement patterns.
	 */
	protected function replaceVersion( $version ) {
		if ( !empty( $this->repoConfig['version-replace'] ) ) {
			foreach ( $this->repoConfig['version-replace'] as $pattern => $replacement ) {
				$version = preg_replace( $pattern, $replacement, $version );
			}
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
		if ( $this->io->isVerbose() ) {
			$this->io->writeError( "Fetching providers from {$this->repoConfig['url']}" );
		}

		// this will be a basic array of provider names
		$this->providerListing = array();
		// this will map {provider name} => {provider url}
		$this->providerHash = array();

		// cycle through the provider path(s)
		foreach ( (array) $this->repoConfig['provider-paths'] as $path ) {
			// form the url to this provider listing - avoid double slashing
			$url = $this->repoConfig['url'] . '/' . trim( $path, '/' ) . '/';
			// if the path ends with a slash, we grab its subdirectories
			if ( substr( $path, -1 ) === '/' ) {
				// try to get a listing of providers
				try {
					$providersRaw = $this->util->execute( 'svn ls', $url );
				} catch( \RuntimeException $e ) {
					throw new \RuntimeException( "SVN Error: Could not retrieve provider listing from $url " . $e->getMessage() );
				}
				// cycle through to remove exclusions
				foreach ( $this->parseSvnList( $providersRaw ) as $name ) {
					// is there an exclude pattern?
					if ( !empty( $this->repoConfig['provider-exclude'] ) ) {
						// should we exclude this provider?
						if ( preg_match( $this->repoConfig['provider-exclude'], $name ) ) {
							continue;
						}
					}
					$this->providerListing[] = $name;
					// $url already has a trailing slash
					$this->providerHash[ $name ] = "$url$name/";
				}
			} else {
				// otherwise we add as-is - the provider name is just the basename of the relative path
				// these explicit providers are not checked to see if they actually exist
				// @todo: optional check to exclude these if 404?
				$name = basename( $path );
				$this->providerListing[] = $name;
				$this->providerHash[ $name ] = $url;
			}
		}
	}

	/**
	 * Take a full `svn ls` response and parse it into an array of items.
	 * @todo  move this to a util class
	 * @param  string $response response from svn
	 * @return array           items returned (could be empty)
	 */
	protected function parseSvnList( $response ) {
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

	/**
	 * No-op
	 */
	function resetPackageIds() {}

	/**
	 * No-op
	 */
	function addPackage( PackageInterface $package ) {}

}
