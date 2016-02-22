#!/usr/bin/env php
<?php

if ( empty( $argv[1] ) ) {
	throw new RuntimeException( 'No URL provided' );
}
$url = rtrim( $argv[1], '/' );
$loops = !empty( $argv[2] ) ? $argv[2] : 100;
$times = [];

echo "Fetching providers from $url ($loops tries)\n\n";

function getJSON( $url ) {
	$contents = @file_get_contents( $url );
	if ( $contents === false ) {
		throw new RuntimeException( "Could not download $url" );
	}
	$json = @json_decode( $contents, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		throw new RuntimeException( "Could not parse response from $url " . json_last_error_msg() );
	}
	return $json;
}

for ( $i = 1; $i <= (int) $loops; $i++ ) {
	echo "Fetching $i of $loops";
	$time_start = microtime( true );
	$retries = 0;
	while ( 1 ) {
		try {
			$packages = getJSON( "$url/packages.json" );
			break;
		} catch ( RuntimeException $e ) {
			$retries++;
		}
	}
	foreach ( $packages['provider-includes'] as $path => $info ) {
		$path = str_replace( '%hash%', $info['sha256'], $path );
		while ( 1 ) {
			try {
				getJSON( "$url/$path" );
				break;
			} catch ( RuntimeException $e ) {
				$retries++;
			}
		}
	}
	$times[] = microtime( true ) - $time_start;
	echo ' - time: ' . end( $times ) . " secs" . ( $retries ? " (with $retries retries)" : '' ) . "\n";
}

sort( $times );
$middle = floor( $loops / 2 );

echo "
Min:    " . min( $times ) . " secs
Max:    " . max( $times ) . " secs
Mean:   " . ( array_sum( $times ) / $loops ) . " secs
Median: " . ( $loops % 2 === 0 ? ( $times[ $middle ] + $times[ $middle + 1 ] ) / 2 : $times[ $middle ] ) . " secs
";
