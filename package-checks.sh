#!/bin/bash
set -e

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

# Check for phpcs
if ! command -v phpcs &>/dev/null; then
    echo "Error: phpcs is not installed or not in your PATH."
    exit 1
fi

if ! phpcs -i | grep -q 'PHPCompatibilityWP'; then
    echo "Error: PHPCompatibilityWP standard is not installed for phpcs."
    exit 1
fi

incompatible_versions=()
compatible_versions=()
fatal_error_detected=0

# Test compatibility against versions specified in $versions
for version in "${versions[@]}"; do
    echo "------------------------------------------"
    echo "Testing PHP compatibility: $version+"
    set +e
    phpcs --standard=PHPCompatibilityWP --extensions=php --runtime-set testVersion "$version-" ./ > phpcs_output.txt 2>&1
    result=$?
    set -e

    if grep -q 'ERROR: Passing an array of values to a property' phpcs_output.txt; then
        echo "❌ Ruleset syntax error detected for PHP $version. Please update your PHPCompatibility ruleset or downgrade PHP_CodeSniffer."
        cat phpcs_output.txt
        fatal_error_detected=1
        break
    elif grep -qE 'ERROR|WARNING' phpcs_output.txt; then
        echo "❌ Incompatibilities found for PHP $version:"
        grep -E 'ERROR|WARNING' phpcs_output.txt
        incompatible_versions+=("$version")
    else
        echo "✅ Compatible with PHP $version+"
        compatible_versions+=("$version")
    fi
    rm phpcs_output.txt
done

echo
if (( fatal_error_detected )); then
    echo "A fatal ruleset/configuration error was detected. Please resolve before trusting the results above."
elif ((${#incompatible_versions[@]} == 0)); then
    echo "🎉 All tested PHP versions are compatible!"
else
    echo "❌ Incompatible PHP versions: ${incompatible_versions[*]}"
    if ((${#compatible_versions[@]} > 0)); then
        echo "✅ Lowest compatible PHP version: ${compatible_versions[0]}"
    else
        echo "❌ No compatible PHP versions found."
    fi
fi

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
    # Update version in package.json and package-lock.json using sed
    sed -i "s/\"version\": \".*\"/\"version\": \"$pluginVer\"/" package.json
    sed -i "s/\"version\": \".*\"/\"version\": \"$pluginVer\"/" package-lock.json
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

