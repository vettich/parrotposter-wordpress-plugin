blank:

i18n-json:
	wp i18n make-json languages --no-purge

dev:
	./bin/deploy-to-dev.sh

zip:
	./bin/make-zip.sh parrotposter.zip

docker-up:
	cd docker && docker compose up -d

docker-down:
	cd docker && docker compose down

docker-logs:
	cd docker && docker compose logs -f wordpress

