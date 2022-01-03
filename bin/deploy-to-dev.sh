#!/bin/bash

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..

# Upload files to dev server
shopt -s extglob
scp -q -r !(.git) www@selen-dev:/var/www/wp.dev.selen.digital/wp-content/plugins/parrotposter/

