# Composer-WP: Composer for WordPress

Composer-WP is a composer plugin that helps manage dependencies for WordPress sites.
Composer-WP enables you to handle WordPress core releases, plugins, and themes entirely through composer.

## Installation

```
$ composer global require balbuf/composer-wp
```

_Composer-WP must be installed globally_ so that the plugin will be loaded before
the project's dependencies are resolved.

As such, it is a good idea to include this as a build step for your project:

```sh
composer global require balbuf/composer-wp
composer install
```

## Description

Similar to [wpackagist](http://wpackagist.org/), Composer-WP leverages the official WordPress SVN repositories
to provide plugins and themes as composer packages. However, the packages which Composer-WP provides are
"virtual"&mdash;Composer-WP creates the packages on-demand by directly referencing the SVN repos to gather
a listing of available packages. Because of this, there is no third-party package repository to query
and the listing is always up-to-date.

Additionally, virtual packages enable the package properties to be dynamic. This allows you to change the
package type simply by adjusting the vendor name, for instance. Each vendor name maps to a package type,
and the package type allows a [custom installer](https://getcomposer.org/doc/articles/custom-installers.md)
to install the package into a specific directory (see: [composer-installers](https://github.com/composer/installers)
and [composer-installers-extender](https://github.com/oomphinc/composer-installers-extender)).
For example, you can install a plugin as either "regular" or "must use" (`wordpress-plugin` or
`wordpress-muplugin`, respectively) just by using the appropriate vendor name.

In addition to the official public WordPress repos, Composer-WP allows you to define your own plugin or theme repos
that contain zipped packages. This type of repo can either reference a local directory or a remote directory
accessible via SSH. The zipped packages are just the standard plugin or theme zip files that you would upload
and install via the WP Admin panel. Composer-WP scans these zips and pulls out meta information from the file
headers (e.g. plugin/theme name and version) to create virtual packages for these. This repo type is useful
for managing packages that are not publicly listed, such as proprietary or paid plugins/themes.

## Features

#### Built-in support for all official WordPress repositories
* [WordPress.org Plugin Directory](https://wordpress.org/plugins/)
* [WordPress.org Theme Directory](https://wordpress.org/themes/)
* [WordPress core releases](https://wordpress.org/download/)
* [WordPress.com free themes](https://theme.wordpress.com/themes/sort/free/)
* [WordPress VIP Plugins](https://vip.wordpress.com/plugins/)
* [WordPress core development repository](https://develop.svn.wordpress.org/) (which includes the unit test framework and additional tools)

#### Manage proprietary/paid plugins and themes by simply dropping them in a directory
Composer-WP provides a new repo type that can contain standard zipped plugins or themes in a private directory.
These zip files are scanned for meta information (e.g. plugin/theme name and version) to create virtual
packages&mdash;no `composer.json` file necessary. These zip files can either be stored in a local directory or
in a directory on a remote server that is accessible via SSH. This is especially useful for paid plugins
or themes that composer cannot download from a public source.

#### Configurable "virtual vendors" which allow for dynamic package types
For example, you can install a plugin as either "regular" or "must use" just by swapping
the vendor name (e.g. `wordpress-plugin/my-plugin` or `wordpress-muplugin/my-plugin`). To resolve
conflicts with vendors from other repositories, each virtual vendor name can be aliased or disabled entirely
via the `extra` property of your `composer.json` file.

#### Full text searching on package name and description
For WordPress repositories that have an accompanying API
([WordPress.org Plugins and Themes](http://codex.wordpress.org/WordPress.org_API) and
[WordPress.com Themes](https://developer.wordpress.com/docs/api/)),
package discovery via `composer search` leverages the full text searching capabilities of the API
to match packages based on their name, slug (composer package name), or description,
in the same way that you would search these directories directly. For other WordPress repositories,
packages are matched by slug or vendor name. Private zip repositories support full text searching
based on the name, slug, and description pulled from the plugin or theme header information.

#### Access to plugins and themes no longer listed in the WordPress directories
Packages that are no longer available in the public directories are usually still available from
the underlying SVN repos. While it is not recommended to rely on these packages, this ensures composer
doesn't suddenly fail if a package is removed from the public directory. Instead, composer will install
the discontinued package and display an "abandoned" notice to alert you.

#### New releases are available immediately
Packages are discovered directly from the source, so there is no sync delay or possibility of
downtime caused by a third-party mirror. New WordPress core releases, as well as plugin and theme updates,
are available the moment they are released.

#### WordPress.org Plugin and Theme repositories are cached efficiently
The WordPress.org plugin and theme directories are massive, each containing thousands of packages.
Composer-WP uses a smart caching technique to obtain incremental updates to the package listing.
The first time a repository is used, Composer-WP obtains the entire package listing that exists
at that point in time. On subsequent requests for that repository, Composer-WP checks only for new packages
and determines a "package delta" with which to update the cached package list. This means dependency
resolving is noticably faster than with wpackagist, whose cache is invalidated approximately every hour
and requires the entire package listing to be downloaded again.

#### Package listings are only downloaded when needed
Repositories are auto-loaded as necessary based on the project's `composer.json` requirements
and/or arguments passed to composer via the command line. For example, this means that if your project
only requires plugins from the WordPress.org directory, no time is wasted by downloading a
list of available themes that you don't care about.

#### Graceful handling of packages that use non-standard version identifiers
Versioning standards are not enforced for packages in the WordPress plugin and theme repos. Instead
of ignoring these packages, Composer-WP will try to normalize the version identifier so that it is
parsable by composer. If all else fails, the package is still provided as a generic 'dev' version.

## Documentation

### Basic Usage

First, make sure Composer-WP is installed globally:

```
$ composer global require balbuf/composer-wp
```

Without any additional configuration, the packages from the official WordPress directories are ready to be used:

```
$ composer require wordpress-plugin/oomph-clone-widgets
```

or searched:

```
$ composer search debug bar
```
