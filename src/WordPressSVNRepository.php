<?php

namespace BalBuf\ComposerWP;

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
 */
class WordPressSVNRepository extends ComposerRepository {

	protected $providersHash; // key providers stored by key for quicker existence check
	protected $providersUrl = true; // we don't need this URL, but we need for it to be truthy
	protected $rootData = array(); // no root data necessary, so this shorts out loadRootServerFile()
	protected $vendors = array(); // vendor name mapping to type
	protected $util; //svn command utility
	protected $distUrl;

	public function __construct( array $repoConfig, IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null ) {
		// @TODO: add event dispatcher?
		$this->url = $repoConfig['url'];
		$this->io = $io;
		$this->loader = new ArrayLoader();
		// used for svn commands
		$this->util = new SvnUtil( '', $io, new Config );

		if ( isset( $repoConfig['dist-url'] ) ) {
			$this->distUrl = $repoConfig['dist-url'];
		}

		if ( isset( $repoConfig['homepage-url'] ) ) {
			$this->homeUrl = $repoConfig['homepage-url'];
		}

		if ( isset( $repoConfig['vendors'] ) && is_array( $repoConfig['vendors'] ) ) {
			$this->vendors = $repoConfig['vendors'];
		}
	}

	public function findPackage( $name, $constraint ) {
		// @todo - where are these used? needs fixing
		// get vendor and package name parts
		if ( count( $parts = explode( '/', $name ) ) === 2 ) {
			list( $vendor, $name ) = $parts;
			if ( isset( $this->vendors[ $vendor ] ) ) {
				$package = parent::findPackage( $name, $constraint );
				$this->configurePackageWithVendor( $package, $vendor );
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
				$this->configurePackageWithVendor( $packages, $vendor );
				return $packages;
			}
		}
		return array();
	}

	/**
	 * Take a package or array of packages and use the requested vendor name
	 * to fill out appropriate settings.
	 * @param  mixed &$package single package or array of packages
	 * @param  string $vendor   the requested vendor
	 */
	protected function configurePackageWithVendor( &$package, $vendor ) {
		if ( $package instanceof PackageInterface ) {
			// set the real name, type, conflicts, etc.
			$name = $package->getName();
			// call the constructor again - the only way to change the name
			$package->__construct( "$vendor/$name", $package->getVersion(), $package->getPrettyVersion() );
			// take the type from the vendor => type mapping
			// we know the vendor exists or we wouldn't have gotten this far
			$package->setType( $this->vendors[ $vendor ] );
			// @TODO: add conflicting packages to avoid user error
		} else if ( is_array( $package ) ) {
			array_walk( $package, function( &$pkg ) use ( $vendor ) {
				$this->configurePackageWithVendor( $pkg, $vendor );
			} );
		}
	}

	/**
	 * Get an array of provider names for this repository.
	 * @return array provider names
	 */
	public function getProviderNames() {
		$this->loadProviderListings();
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

		// vendor does not match one of our virtual vendors
		if ( !isset( $this->vendors[ $vendor ] ) ) {
			return array();
		}

		// we already got it!
		if ( isset( $this->providers[ $shortName ] ) ) {
			return $this->providers[ $shortName ];
		}

		// make sure they're loaded
		$this->loadProviderListings();

		//echo "\n\n\n\nYAY - $name, $vendor\n\n\n\n\n";
		// package does not exist in this repo?
		if ( !isset( $this->providerHash[ $shortName ] ) ) {
			return array();
		}

		// try to get a listing of tags
		try {
			if ( $this->io->isVerbose() ) {
				$this->io->writeError("Fetching available versions for $name");
			}
			$tags = $this->util->execute( 'svn ls', implode( '/', array( $this->url, $shortName, 'tags' ) ) );
		} catch( \RuntimeException $e ) {
			throw new \RuntimeException( "SVN Error: Could not retrieve tag listing for $name. " . $e->getMessage() );
		}
		$tags = $this->parseSvnList( $tags );

		$this->providers[ $shortName ] = array();

		foreach ( $tags as $tag ) {
			if ( !$pool->isPackageAcceptable( $shortName, VersionParser::parseStability( $tag ) ) ) {
				continue;
			}
			$data = array(
				// piece the full name back together
				'name' => $name,
				'version' => $tag,
				'type' => $this->vendors[ $vendor ],
				'dist' => array(
					'type' => 'zip',
					// spaces appear to be stripped in version name. see: https://wordpress.org/plugins/woocommerce-quick-donation/developers/
					// not sure about other character sanitization
					'url' => str_replace( array( '%name%', '%version%', ' ' ), array( $shortName, $tag, '' ), $this->distUrl ),
				),
				'source' => array(
					'type' => 'svn',
					'url' => "{$this->url}/$shortName",
					'reference' => "tags/$tag",
				),
				'homepage' => str_replace( '%name%', $shortName, $this->homeUrl ),
				'require' => array(
					'oomphinc/composer-installers-extender' => '~1.0',
				),
			);
			$package = $this->createPackage( $data, 'Composer\Package\CompletePackage' );
			$package->setRepository( $this );
			$this->providers[ $shortName ][ $tag ] = $package;

			// handle root aliases - not sure if they will apply here (this was copped from the parent class)
			if ( isset( $this->rootAliases[ $package->getName() ][ $package->getVersion() ] ) ) {
				$rootAliasData = $this->rootAliases[ $package->getName() ][ $package->getVersion() ];
				$alias = $this->createAliasPackage( $package, $rootAliasData['alias_normalized'], $rootAliasData['alias'] );
				$alias->setRepository( $this );
				$this->providers[ $shortName ][ $tag . '-root' ] = $alias;
			}
		}
		// @TODO: handle dev trunk here

		return $this->providers[ $shortName ];

	}

	// no data loaded at this point
	public function loadDataFromServer() {
		return array();
	}

	protected function loadRootServerFile() {
		return;
	}

	protected function loadProviderListings( $data = null ) {
		// maybe we already loaded them?
		if ( $this->providerListing !== null ) {
			return;
		}
		if ( $this->io->isVerbose() ) {
			$this->io->writeError( "Fetching packages from {$this->url}" );
		}
		// load up the provider listings into the cache rn
		$this->providerListing = array();
		// try to get a listing of providers
		try {
			$providers = $this->util->execute( 'svn ls', $this->url );
		} catch( \RuntimeException $e ) {
			throw new \RuntimeException( 'SVN Error: Could not retrieve package listing. ' . $e->getMessage() );
		}
		$this->providerListing = $this->parseSvnList( $providers );
		$this->providerHash = array_flip( $this->providerListing );
	}

	/**
	 * Take a full `svn ls` response and parse it into an array of items.
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

}
