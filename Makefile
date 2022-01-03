blank:

i18n-json:
	wp i18n make-json languages --no-purge

dev:
	./bin/deploy-to-dev.sh

zip:
	./bin/make-zip.sh parrotposter.zip

