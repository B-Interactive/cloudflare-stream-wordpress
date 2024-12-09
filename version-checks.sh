#!/bin/bash

# Test PHP version compatibility
versions=(7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4)

for version in ${versions[@]}; do
    echo "Testing project compatibility against PHP $version"
    phpcs -p -s -v --runtime-set testVersion $version- --standard=PHPCompatibilityWP --severity=1 ./ --extensions=php | grep -E 'ERROR|WARNING'
done

# Update minimum required PHP version
echo "Current plugin value: " $(cat readme.txt | grep "Requires PHP")
read -p "Update minimum required PHP version to (blank for unchanged): " -r minPHP
if [ -n $minPHP ]; then
    sed -i "Requires PHP:*/c Requires PHP: $minPHP" ./readme.txt
fi

# Update plugin version
echo "Current plugin value: " $(cat readme.txt | grep "Version:")
read -p "Update plugin version to (blank for unchanged): " -r pluginVersion
if [ -n $pluginVersion ]; then
    sed -i "/Version:*/c Version: $pluginVersion" ./readme.txt
    sed -i "/Version:*/c \ * Version: $pluginVersion" ./cloudflare-stream.php
fi

# Update WordPress tested vresion
echo "Current plugin value: " $(cat readme.txt | grep "Tested up to")
read -p "Update WordPress tested version (blank for unchanged): " -r wpTested
if [ -n $wpTested ]; then
    sed -i "/Tested up to:*/c Tested up to: $wpTested" ./readme.txt
fi

# PHP and JS Linting
read -p -n 1 "Lint PHP against WordPress coding standards? [y/n]: " phpStandard
if [[ "$phpStandard" == [Yy]* ]]; then
    phpcbf -d memory_limit=1G -p *.php --standard=WordPress --extensions=php
    phpcbf -d memory_limit=1G -p src/ --standard=WordPress --extensions=php

    echo "Check any unresolved issues with:"
    echo "phpcs -s -d memory_limit=1G -p src/ --standard=WordPress --extensions=php"
fi

read -p -n 1 "Lint JavaScript against WordPress coding standards? [y/n]: " jsStandard
if [[ "$jsStandard" == [Yy]* ]]; then
    phpcbf -d memory_limit=1G -p *.js --standard=WordPress --extensions=js
    phpcbf -d memory_limit=1G -p src/ --standard=WordPress --extensions=js

    echo "Check any unresolved issues with:"
    echo "phpcs -s -d memory_limit=1G -p src/ --standard=WordPress --extensions=js"
fi
