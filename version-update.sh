#!/bin/bash

read -p "Version to update to: " -r
sed -i "/Version:*/c Version: $REPLY" ./readme.txt
sed -i "/Version:*/c \ * Version: $REPLY" ./cloudflare-stream.php

read -p "Tested up to WordPress: " -r
sed -i "/Tested up to:*/c Tested up to: $REPLY" ./readme.txt
