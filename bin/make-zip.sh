#!/bin/sh
#
# Build a installable plugin zip

ZIP_FILE=${1:-parrotposter.zip}

rm "${ZIP_FILE}"

zip -r "${ZIP_FILE}" \
	assets/ \
	includes/ \
	languages/ \
	src/ \
	views/ \
	index.php \
	LICENSE \
	parrotposter.php \
	readme.txt
