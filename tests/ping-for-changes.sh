#!/usr/bin/env bash

# Ping a URL every X seconds and save a new copy if it has changed.
# Runs indefinitely until terminated

url=$1
delay=${2:-60} #default 60 secs
output=${3:-artifacts} #output dir
last=''

if [ -z "$url" ]; then
	echo 'Must pass a URL as the first argument'
	exit 1
fi

filename=$(basename "$url")
mkdir -p "$output"

while true; do
	echo "Pinging $url"
	response=$(curl -s "$url")
	if [[ "$response" != "$last" ]]; then
		date=$(date "+%F_%H.%M.%S")
		echo "Saving new response $date"
		echo "$response" > "$output/${date}_$filename"
	fi
	last="$response"
	echo "Waiting for $delay seconds"
	sleep $delay
done
