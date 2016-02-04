<?php

/**
 * Zip Repository config base class.
 */

namespace BalBuf\ComposerWP\Repository\Config;

class ZipRepositoryConfig extends RepositoryConfig {

	const repoType = 'wp-zip';
	protected static $configDefaults = [
		// rel or absolute path
		'url' => null,
		// ssh [user@]hostname
		'ssh' => null,
		// types and vendors that will be recognized
		// {type} => {vendors}
		// types must be any of the keys below - vendors can be anything
		'package-types' => [
			'wordpress-plugin' => 'wordpress-plugin',
			'wordpress-muplugin' => 'wordpress-muplugin',
			'wordpress-theme' => 'wordpress-theme',
		],
		// max depth to traverse directories looking for zip files
		// falsey or negative value for no max depth
		'max-depth' => null,
		// callable that will receive the package name to manipulate
		'name-filter' => null,
		// callable that will receive the package version to manipulate
		'version-filter' => null,
		// callable that will receive the package object to manipulate
		'package-filter' => null,
	];

}
