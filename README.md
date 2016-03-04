# Composer-WP: Composer for WordPress

Composer-WP is a composer plugin that helps manage dependencies for WordPress sites.
Composer-WP enables you to handle WordPress core releases, plugins, and themes entirely through composer.

## Installation

```
$ composer global require balbuf/composer-wp
```

_**Composer-WP must be installed globally**_ so that the plugin will be loaded before
the project's dependencies are resolved.

As such, it is a good idea to include this as a build step for your project:

```sh
composer global require balbuf/composer-wp
composer install
```

## About

Similar to [wpackagist](http://wpackagist.org/), Composer-WP leverages the official WordPress SVN repositories
to provide plugins and themes as composer packages. However, the packages which Composer-WP provides are
"virtual"&mdash;Composer-WP creates the packages on-demand by directly referencing the SVN repos to gather
a listing of available packages. Because of this, there is no third-party package repository to query
and the listing is always up-to-date.

Additionally, virtual packages enable the package properties to be dynamic. This allows you to change the
package type simply by adjusting the vendor name, for instance. Each vendor name maps to a package type,
and the package type dictates where the package will be installed. For example, you can install a
plugin as either "regular" or "must use" (`wordpress-plugin` or `wordpress-muplugin`, respectively)
just by using the appropriate vendor name.

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
* [WordPress core development repository](https://develop.svn.wordpress.org/) (includes the unit test framework and i18n tools)

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

#### Load packages automatically with the included mu-plugins autoloader
Any regular plugins installed as "must use" plugins will be automatically loaded in WordPress if the
mu-plugins autoloader is enabled (the default setting). These "must use" plugins will be loaded
in the order they appear in your `composer.json`, which allows you to explicitly specify a loading
precedence in case certain plugins depend on others. The mu-plugins autoloader will also pull in
composer's own autoloader, allowing you to easily make use of any non-WordPress packages within your
WordPress project. The mu-plugin autoloader is inspired by and based upon the [Bedrock autoloader](https://roots.io/).

#### Full text searching on package name and description
For WordPress repositories that have an accompanying API
([WordPress.org Plugins and Themes](http://codex.wordpress.org/WordPress.org_API) and
[WordPress.com Themes](https://developer.wordpress.com/docs/api/)),
package discovery via `composer search` leverages the full text searching capabilities of the API
to match packages based on their name, slug (composer package name), or description,
in the same way that you would search these directories directly. For other WordPress repositories,
packages are matched by slug or vendor name. Private zip repositories support full text searching
based on the name, slug, and description pulled from the plugin or theme header information.

#### Built-in custom installer which helps you place packages where you need them
Composer supports [custom installers](https://getcomposer.org/doc/articles/custom-installers.md)
that allow you to control where packages are installed to, e.g. plugins and themes go into your
`wp-content` directory. For example, the [composer-installers](https://github.com/composer/installers)
plugin is widely used and handles WordPress themes and plugins (but not core files). An additional
installer plugin is not required as the Composer-WP installer handles all WordPress package types
and is specifically catered towards WordPress projects.

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
Versioning standards are not enforced for packages in the WordPress plugin repo. Instead
of ignoring these packages, Composer-WP will try to normalize the version identifier so that it is
parsable by composer. If all else fails, the package is still provided as a generic 'dev' version.

## Documentation

### Basic Usage

First, make sure Composer-WP is installed globally:

```
$ composer global require balbuf/composer-wp
```

Without any additional configuration, packages from the official WordPress directories are ready to be used:

```
$ composer require wordpress-plugin/oomph-clone-widgets
```

### Determining a Package Name
The package names used by Composer-WP are formed using a vendor name specific to the particular repo and
the package "slug":

```
vendor/slug
```

The vendor names and package naming conventions for each built-in WordPress repo can be found [below](#built-in-wordpress-repositories).

#### Plugins and Themes
For plugins and themes in the WordPress directories, the slug is the sanitized name of the package
that appears in its URL.

For example, suppose you want to install the Contact Form 7 plugin:
[https://wordpress.org/plugins/contact-form-7/](https://wordpress.org/plugins/contact-form-7/). The slug of the plugin would be `contact-form-7`, and the default vendor name for the WordPress.org Plugin Directory is `wordpress-plugin`.
The full package name would be `wordpress-plugin/contact-form-7`.

>##### Package Name Bookmarklet
>For convenience in determining the proper package name for a plugin or theme, you can add the following
>JavaScript snippet as a bookmarklet in your browser. If you are on the detail page for a plugin or theme on
>[wordpress.org](https://wordpress.org/), [theme.wordpress.com](https://theme.wordpress.com/themes/sort/free/),
>or [vip.wordpress.com](https://vip.wordpress.com/plugins/), this bookmarklet will present you with
>the package name using the default vendor name for that repo:
>
>```js
>javascript:void((function(r,l,w,h,s,p,e,u,m){l=l.match.bind(l);u=h+w+'\\.org\\/';if(m=l(new r(u+'themes'+s)))return p(e,w+'-theme/'+m[1]);if(m=l(new r(u+'plugins'+s)))return p(e,w+'-plugin/'+m[1]);if(m=l(new r(h+'theme\\.'+w+'\\.com\\/themes'+s)))return p(e,w+'-com/'+m[1]);if(m=l(new r(h+'vip\\.'+w+'\\.com\\/plugins'+s)))return p(e,w+'-vip/'+m[1]);alert(e+' not found')})(RegExp,location.href,'wordpress','^https?:\\/\\/','\\/([^\\/]+)\\/?$',prompt,'Composer-WP package name:'));
>```

#### WordPress Core

WordPress core releases use `wordpress` as both the vendor and slug:

```
$ composer require wordpress/wordpress
```

#### Find Packages with Composer Commands

`composer search` and `composer show` are useful commands for finding and verifying packages.

You can use `composer search` to find a package name:

```
$ composer search contact form 7
```

Where possible, Composer-WP uses full text searching to match against names and descriptions.

You can use `composer show -a` to get more information about a package or simply verify that it exists:

```
$ composer show -a wordpress-plugin/contact-form-7
```

If the package exists, composer will provide additional details such as available versions.

#### composer.json Example

A simple `composer.json` file for a WordPress site might look like:

```json
{
  "name": "My WordPress Site",
  "require": {
    "wordpress/wordpress": "^4.4",
    "wordpress-theme/zoo": "~1.8",
    "wordpress-plugin/getty-images": "^2.4",
    "wordpress-muplugin/wordpress-importer": "*"
  },
  "require-dev": {
    "wordpress-plugin/debug-bar": "0.8.2"
  }
}
```

#### Further Reading

Please refer to the official [composer documentation](https://getcomposer.org/doc/) for more information on general usage.

### Additional Configuration

Composer-WP has additional configuration options that can be specified in the `"composer-wp"` section
of the [`"extra"` property](https://getcomposer.org/doc/04-schema.md#extra) of your `composer.json` file.
The default settings are configured with a typical WordPress project in mind, so you may not need to
alter these options for basic use.

```json
  "extra": {
    "composer-wp": {
      "repositories": [],
      "vendors": {},
      "installer": {}
    }
  }
```

#### Repositories

The `"repositories"` property is an array of repository configurations that affect the built-in repos or
define new custom repos.

##### Built-In Repositories

The most simple use for the repositories property is specifying built-in repos to either enable or disable:

```json
"repositories": [
  {
    "themes": false
  },
  {
    "develop": true
  }
]
```

This configuration would disable the WordPress.org Themes Directory and enable the WordPress core development
repository. The repositories are referenced by their repo names which can be found below.
All built-in repos (except for the core development repository) are automatically loaded when
packages that they handle are requested via `composer.json` or command line arguments, so generally, built-in
repos do not need to be enabled here. However, you can use this to disable repos that you don't want to use
(for instance to speed up certain commands such as `composer search`). If you wish to use the WordPress
core development repo, you must explicitly enable it as shown above. Enabling or disabling built-in repos
can also be combined into a single object:

```json
"repositories": [
  {
    "themes": false,
    "develop": true
  }
]
```

##### Custom Repositories

You can also define new repositories here:

```json
"repositories": [
  {
    "type": "wp-zip",
    "url": "/private-plugins",
    "ssh": "user@example.com",
    "max-depth": 1
  }
]
```

Composer-WP supports two repository types: `wp-zip` and `wp-svn`. Each have their own properties, but share
the following:

* *type* _(required)_

  `wp-zip` or `wp-svn`

* *package-types* _(required)_

  This is an object that defines which package types are supported and maps types to vendor names. For example:
  ```json
  "package-types": {
    "wordpress-plugin": "wpackagist-plugin",
    "wordpress-theme": "wpackagist-theme"
  }
  ```
  This would allow a repo to handle packages that use wpackagist's vendor names.

* *url* _(required)_

  Depending on the repo type, this defines either a URL or a path.

Properties specific to repo type:

* *wp-zip* - This repository type allows you to scan a directory for plugin or theme zip files either locally or
remotely via SSH.

  * *url* _(required)_

    The url property defines the directory path where the zip files reside. For SSH repos, this is _only_ the path,
    not including the server's hostname.

  * *ssh*

    This property defines the SSH connection information (if applicable): `[user@]host`. The format is
    exactly how it would be passed to `ssh`. Default is `null` - repo path is local.

  * *package-types* _(required)_

    Default:
    ```json
    "package-types": {
      "wordpress-plugin": "wordpress-plugin",
      "wordpress-muplugin": "wordpress-muplugin",
      "wordpress-theme": "wordpress-theme"
    }
    ```

  * *max-depth*

    Maximum number of directories to traverse within the specified path. Default is `null` - no limit.

* *wp-svn* - This repository type is used internally for the built-in repos and allows a public SVN repository
to act as a composer package repository.

#### Vendors

The `"vendors"` property allows you to create vendor aliases and disable existing vendor names. This
property is a simple object that maps a new vendor name to an existing name, or maps an existing
vendor name to `false` to disable its use:

```json
"vendors": {
  "wpackagist-plugin": "wordpress-plugin",
  "wpcom-themes": "wordpress-com",
  "wordpress-com": false
}
```

The above would configuration would allow plugins to be required as if they came from wpackagist and
would replace the default WordPress.com Themes repo vendor name of `wordpress-com` with `wpcom-themes`.
Note that the original vendor name of `wordpress-com` will no longer be recognized, but both
`wpackagist-plugin` and `wordpress-plugin` will be recognized.

#### Installer

The `"installer"` property allows you to configure the built-in installer. The installer is designed
to play nicely with other custom installers you may be using. That is, if there are no other custom
installers, Composer-WP will handle WordPress packages by default. However, if there is another
custom installer (such as [composer-installers-extender](https://github.com/oomphinc/composer-installers-extender))
that is configured to handle WordPress packages, Composer-WP will allow that installer to handle
the packages unless explicitly instructed to do so (by specifying install paths).

The built-in installer has the following properties:

* *wordpress-path* (_default:_ `wp`)

  This defines where you wish WordPress core files to be installed to, relative to the project root.
  Note that when composer installs a package, it completely empties the target directory before
  installing the new files. As such, the WordPress path should be designated for WordPress core files
  only, as anything else (e.g. plugins, themes, and `wp-config.php`) will be wiped away on install or update.
  It is recommended that you keep your `wp-config.php` file in the parent directory of the WordPress path
  (WordPress can find it there automatically) and replace the `wp-content` directory with a symlink to
  your real `wp-content` folder. (See the `symlink-wp-content` option below.)

* *wp-content-path* (_default:_ `wp-content`)

  This defines where themes, plugins, and mu-plugins will be installed to, relative to the project root.
  The path will be treated like a standard `wp-content` folder, i.e. packages will be installed to the
  `themes`, `plugins`, and `mu-plugins` subdirectories of this path. This must be specified to use the
  `symlink-wp-content` option.

* *wpmu-plugin-dir*

  This allows you to specify a mu-plugins path different from the standard one placed within wp-content.
  WordPress allows you to define a constant which alters the `mu-plugins` path, so this allows you to
  install mu-plugin packages to your alternate path. If specified, this supersedes the `wp-content`-based
  path that would be used otherwise.

* *path-mapping*

  If you require more granular control of the WordPress package types, you can specify each type
  separately here with a mapping to its install path. For example:

  ```json
  "path-mapping": {
    "wordpress-theme": "wp-content/themes/my-custom-themes"
  }
  ```

  Note that `wordpress-muplugin` and `wordpress-core` types are superseded by `wpmu-plugin-dir`
  and `wordpress-path` properties, respectively, if explicitly set.

* *symlink-wp-content* (_default:_ `true`)

  This option allows Composer-WP to automatically replace the `wp-content` directory that comes
  with the WordPress core files with a symlink to the directory set in `wp-content-path`. This
  allows the WordPress core files to be cleared and reinstalled as necessary without affecting
  any other packages. Whenever WordPress core is updated, the symlink will be restored.

* *mu-plugin-autoloader* (_default:_ `true`)

  This option allows you to enable or disable the included mu-plugins autoloader. If enabled,
  all regular plugins installed in the mu-plugins directory will be automatically loaded in
  WordPress in the order that they are defined in `composer.json`. Additionally, the composer
  autoloader will be loaded into WordPress. In order to use this option, either the
  `wp-content-path` or `wpmu-plugin-dir` must be defined (default values are considered).

* *dev-first* (_default:_ `false`)

  This option determines if `require-dev` mu-plugins will be loaded first by the autoloader.
  By default, the mu-plugins in `require` are loaded before any in `require-dev`.


The installer can be disabled entirely by setting this property to false:

```json
"installer": false
```

### Built-in WordPress Repositories

Composer-WP has several built-in repositories that are automatically loaded when you request packages from them.
Each repo has its own set of vendor names which are used to determine which repos to load for the request packages.
The repo name can be used to explicitly enable or disable the given repo via your `composer.json` file (see below).

#### WordPress.org Plugins ([https://wordpress.org/plugins/](https://wordpress.org/plugins/))
Plugins developed by the WordPress community.

||Details|
|---|---|
|**Repo Name**|`plugins`|
|**Vendor Names**|`wordpress-plugin` (package type: `wordpress-plugin`) and `wordpress-muplugin` (package type: `wordpress-muplugin`)|
|**Package Names**|Package names match the slug found in the plugin's URL, e.g. [https://wordpress.org/plugins/oomph-clone-widgets/](https://wordpress.org/plugins/oomph-clone-widgets/) is `oomph-clone-widgets`.|
|**Versions**|Version releases as well as `dev-trunk`.|
|**SVN Source**|[https://plugins.svn.wordpress.org/](https://plugins.svn.wordpress.org/)|
|**Caching**|The package listing is cached for as long as `composer config cache-ttl` if it doesn't change. However, each time the repo is used, a "package delta" is obtained to update this cached list with new package names, so the cache is likely to change regularly.|

#### WordPress.org Themes ([https://wordpress.org/themes/](https://wordpress.org/themes/))
Themes developed by the WordPress community.

||Details|
|---|---|
|**Repo Name**|`themes`|
|**Vendor Names**|`wordpress-theme` (package type: `wordpress-theme`)|
|**Package Names**|Package names match the slug found in the theme's URL, e.g. [https://wordpress.org/themes/twentyfourteen/](https://wordpress.org/themes/twentyfourteen/) is `twentyfourteen`.|
|**Versions**|Version releases only; no `dev-trunk`.|
|**SVN Source**|[https://themes.svn.wordpress.org/](https://themes.svn.wordpress.org/)|
|**Caching**|The package listing is cached for as long as `composer config cache-ttl` if it doesn't change. However, each time the repo is used, a "package delta" is obtained to update this cached list with new package names, so the cache is likely to change regularly.|

#### WordPress Core ([https://wordpress.org/download/](https://wordpress.org/download/))
WordPress core releases, including major versions and security/bugfix updates.

||Details|
|---|---|
|**Repo Name**|`core`|
|**Vendor Names**|`wordpress` or `wordpress-core` (package type: `wordpress-core`)|
|**Package Names**|`wordpress` is the only package.|
|**Versions**|Version releases as well as `dev-trunk`.|
|**SVN Source**|[https://core.svn.wordpress.org/](https://core.svn.wordpress.org/)|
|**Caching**|This package listing is not cached by default to ensure updates are available immediately.|

#### WordPress.com Themes ([https://theme.wordpress.com/](https://theme.wordpress.com/themes/sort/free/))
Free themes that are offered for WordPress.com hosted sites but may also be used on self-hosted sites.

||Details|
|---|---|
|**Repo Name**|`wpcom-themes`|
|**Vendor Names**|`wordpress-com` (package type: `wordpress-theme`)|
|**Package Names**|Package names match the slug found in the theme's URL, e.g. [https://theme.wordpress.com/themes/balloons/](https://theme.wordpress.com/themes/balloons/) is `balloons`.|
|**Versions**|`dev-master` only; no individual version releases.|
|**SVN Source**|[https://wpcom-themes.svn.automattic.com/](https://wpcom-themes.svn.automattic.com/)|
|**Caching**|This package listing is cached for 1 week by default as the list changes infrequently.|

#### WordPress VIP Plugins ([https://vip.wordpress.com/plugins/](https://vip.wordpress.com/plugins/))
Plugins that are sanctioned for use on WordPress VIP hosted sites. _Note:_ These plugins may not work correctly outside of
the WordPress VIP environment. See the [VIP Quickstart documentation](https://vip.wordpress.com/documentation/quickstart/)
for more information about replicating the WordPress VIP environment.

||Details|
|---|---|
|**Repo Name**|`vip-plugins`|
|**Vendor Names**|`wordpress-vip` (package type: `wordpress-plugin`)|
|**Package Names**|Package names generally match the slug found in the plugin's URL, e.g. [https://vip.wordpress.com/plugins/ooyala/](https://vip.wordpress.com/plugins/ooyala/) is `ooyala`. WordPress VIP also has a "release candidate" process to evaluate plugins before they are officially released. These package names are appended with `-rc`.|
|**Versions**|`dev-master` only; no individual version releases.|
|**SVN Source**|[https://vip-svn.wordpress.com/plugins/](https://vip-svn.wordpress.com/plugins/)|
|**Caching**|This package listing is cached for 1 week by default as the list changes infrequently.|

#### WordPress Core - Development Version ([https://develop.svn.wordpress.org/](https://develop.svn.wordpress.org/))
The WordPress core development repo stays in sync with the main core repo but also includes the unit test framework and
additional tools for internationalization. _Note:_ Unlike the the other built-in repos, the `develop` repo must be explicitly
enabled to use as a dependency, as it shares a vendor namespace with the regular `core` repo.

||Details|
|---|---|
|**Repo Name**|`develop`|
|**Vendor Names**|`wordpress` or `wordpress-core` (package type: `wordpress-develop`)|
|**Package Names**|`develop` is the only package.|
|**Versions**|Version releases as well as `dev-trunk`.|
|**SVN Source**|[https://develop.svn.wordpress.org/](https://core.svn.wordpress.org/)|
|**Caching**|This package listing is not cached by default to ensure updates are available immediately.|
