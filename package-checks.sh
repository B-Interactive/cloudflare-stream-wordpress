#!/bin/bash

# ===== Required Tools and Rulesets Check =====

# CLI tools
REQUIRED_TOOLS=(phpcs phpcbf stylelint)
for tool in "${REQUIRED_TOOLS[@]}"; do
    if ! command -v "$tool" &>/dev/null; then
        echo "❌ Required tool '$tool' is not installed or not in PATH."
        missing_any=1
    else
        echo "✅ Found '$tool'"
    fi
done

# PHP_CodeSniffer standards
if command -v phpcs &>/dev/null; then
    phpcs_standards=$(phpcs -i | sed 's/.*: //; s/, /\n/g')
    for standard in PHPCompatibilityWP WordPress; do
        if ! grep -q "^$standard$" <<<"$phpcs_standards"; then
            echo "❌ phpcs standard '$standard' is missing."
            missing_any=1
        else
            echo "✅ phpcs standard '$standard' is available."
        fi
    done
fi

# Stylelint config standard: Try a dry run
if command -v stylelint &>/dev/null; then
    # Check for Stylelint config file
    if ! ls .stylelintrc* stylelint.config.* package.json 2>/dev/null | grep -q .; then
        echo "❌ No Stylelint config file (.stylelintrc, stylelint.config.js, etc) found in this directory."
        missing_any=1
    elif ! stylelint --print-config . 1>/dev/null 2>&1; then
        echo "❌ stylelint-config-standard is installed but not available to Stylelint in this directory."
        echo "Check your NODE_PATH or ensure your config extends 'stylelint-config-standard'."
        echo "If using Arch, you may need to set: export NODE_PATH=/usr/lib/node_modules"
        missing_any=1
    else
        echo "✅ stylelint-config-standard is available to stylelint."
    fi
fi

# Final check
if [[ $missing_any == 1 ]]; then
    echo
    echo "One or more required tools or rulesets are missing."
    echo "Please install them by your preferred means (package manager, npm, composer, etc)."
    echo "After installation, ensure all tools and rulesets are available in your PATH and project."
    exit 1
else
    echo
    echo "All required tools and rulesets are available."
fi

# ===== End Required Tools and Rulesets Check =====

# Update readme.txt.
# $1 lineMatch value.
# $2 new value/version.
updateReadme() {
    sed -i "/$1*/c $1 $2" ./readme.txt
}

# Update cloudflare-stream.php.
# $1 lineMatch value.
# $2 new value/version.
updateCloudflareStreamPHP() {
    sed -i "/$1*/c \ * $1 $2" ./cloudflare-stream.php
}

# Test PHP version compatibility
versions=(7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4)

incompatible_versions=()
compatible_versions=()
fatal_error_detected=0

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

    # Update package.json and package-lock.json to match
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
read -p "Lint CSS with Stylelint against stylelint-config-standard? [y/n]: " cssStandard
if [[ "$cssStandard" == [Yy]* ]]; then
    # You may want to adjust the path pattern as needed (e.g., 'src/**/*.css' or just '*.css')
    stylelint "**/*.css"
    # Optionally, prompt for listing outstanding issues (stylelint outputs all by default)
else
    echo "Skipping CSS linting..."
fi
