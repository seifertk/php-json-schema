bench:
	php -n ./vendor/bin/phpunit -v --configuration phpunit.xml
	mv benchmark-result.json benchmark-result2.json
	php -n ./vendor/bin/phpunit -v --configuration phpunit.xml
	php -n ./vendor/bin/phpunit-bench compare benchmark-result.json benchmark-result2.json
