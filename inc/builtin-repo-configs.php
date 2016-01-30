<?php

/**
 * Repo definitions for buitin repos.
 */

				/*'dist' => array(
					'type' => 'zip',
					// spaces appear to be stripped in version name. see: https://wordpress.org/plugins/woocommerce-quick-donation/developers/
					// not sure about other character sanitization
					'url' => str_replace( array( '%name%', '%version%', ' ' ), array( $shortName, $tag, '' ), $this->distUrl ),
				), */
//'homepage' => str_replace( '%name%', $shortName, $this->homeUrl ),

return array(
	'plugins' => array(
		'url' => 'http://plugins.svn.wordpress.org/',
		'package-paths' => array( '/tags/', '/trunk' ),
		'types' => array( 'wordpress-plugin' => 'wpackagist-plugin', 'wordpress-muplugin' => 'wordpress-muplugin' ),
		'package-filter' => '',
	),
	'themes' => array(),
	'core' => array(),
	'develop' => array(),
	'wpcom-themes' => array(),
	'vip-plugins' => array(),
);
