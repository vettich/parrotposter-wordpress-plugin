#!/bin/sh

VERSION=$1

sed -i -E "/\* Version:/s/[0-9]+.[0-9]+.[0-9]+/$VERSION/g" parrotposter.php
sed -i -E "/PARROTPOSTER_VERSION/s/[0-9]+.[0-9]+.[0-9]+/$VERSION/g" parrotposter.php
sed -i -E "/Stable tag:/s/[0-9]+.[0-9]+.[0-9]+/$VERSION/g" readme.txt

