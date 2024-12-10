#!/bin/bash

# Update readme.txt.
# $1 lineMatch value.
# $2 new value/version.
updateReadme() {
    sed -i "/$1*/c $1 $2" ./readme.txt
}

# Update readme.txt.
# $1 lineMatch value.
# $2 new value/version.
updateCloudflareStreamPHP() {
    sed -i "/$1*/c \ * $1 $2" ./cloudflare-stream.php
}

# Test PHP version compatibility
versions=(7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4)

for version in ${versions[@]}; do
    echo "Testing project compatibility against PHP $version"
    #phpcs -p -s -v --runtime-set testVersion $version- --standard=PHPCompatibilityWP --severity=1 ./ --extensions=php | grep -E 'ERROR|WARNING'
done

# Update minimum required PHP version
echo
lineMatch="Requires PHP:"
currentVer=$(cat readme.txt | grep "$lineMatch" | awk -F " " '{print $3}')
echo "Current plugin value: $lineMatch $currentVer"
read -p "Update minimum required PHP version to (blank for unchanged): " -r minPHP
if [[ $minPHP =~ [0-9] ]];then
    echo "Updating minimum required PHP version from $currentVer to $minPHP."
    updateReadme "$lineMatch" "$minPHP"
else
    echo "Skipping..."
fi

# Update plugin version
lineMatch="Version:"
currentVer=$(cat readme.txt | grep "$lineMatch" | awk -F " " '{print $2}')
echo "Current plugin value: $lineMatch $currentVer"
read -p "Update plugin version to (blank for unchanged): " -r pluginVer
if [[ $pluginVer =~ [0-9] ]]; then
    echo "Updating plugin version from $currentVer to $pluginVer."
    updateReadme "$lineMatch" "$pluginVer"
    updateCloudflareStreamPHP "$lineMatch" "$pluginVer"
else
    echo "Skipping..."
fi

# Update WordPress tested version
lineMatch="Tested up to:"
currentVer=$(cat readme.txt | grep "$lineMatch" | awk -F " " '{print $4}')
echo "Current plugin value: $lineMatch $currentVer"
read -p "Update WordPress tested version (blank for unchanged): " -r wpTested
if [[ $wpTested =~ [0-9] ]]; then
    updateReadme "$lineMatch" "$wpTested"
else
    echo "Skipping..."
fi

# PHP Linting
read -p "Lint PHP against WordPress coding standards? [y/n]: " phpStandard
if [[ "$phpStandard" == [Yy]* ]]; then
    phpcbf -d memory_limit=1G -p *.php --standard=WordPress --extensions=php
    phpcbf -d memory_limit=1G -p src/ --standard=WordPress --extensions=php

    read -p "List outstanding issues?: " -r yn
    if [[ "$yn" == [Yy]* ]]; then
        phpcs -s -d memory_limit=1G -p src/ --standard=WordPress --extensions=php
    fi
fi

# JavaScript Linting
read -p "Lint JavaScript against WordPress coding standards? [y/n]: " jsStandard
if [[ "$jsStandard" == [Yy]* ]]; then
    phpcbf -d memory_limit=1G -p *.js --standard=WordPress --extensions=js
    phpcbf -d memory_limit=1G -p src/ --standard=WordPress --extensions=js

    read -p "List outstanding issues?: " -r yn
    if [[ "$yn" == [Yy]* ]]; then
        phpcs -s -d memory_limit=1G -p src/ --standard=WordPress --extensions=js
    fi
fi

# CSS Linting

