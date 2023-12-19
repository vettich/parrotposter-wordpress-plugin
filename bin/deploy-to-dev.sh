#!/bin/bash

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..

# Upload files to dev server
shopt -s extglob
remote_dir="/var/lib/docker/volumes/selen-wp_www/_data/wp-content/plugins/parrotposter/"
scp -q -r !(.git) root@selen-dev:$remote_dir
ssh root@selen-dev "sed -i 's/parrotposter.com/dev.parrotposter.com/' $remote_dir/src/Api.php"

