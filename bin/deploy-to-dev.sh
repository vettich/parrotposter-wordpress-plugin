#!/bin/bash

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..

# Upload files to dev server
shopt -s extglob
remote_dir="/var/lib/docker/volumes/wp_wordpress/_data/wp-content/plugins/parrotposter/"
#scp -q -r -P 8422 !(.git) root@proxy-dev-server:$remote_dir
rsync -avzh --progress -r -e 'ssh -p 8422' !(.git) root@proxy-dev-server:$remote_dir
