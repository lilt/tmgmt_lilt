TMGMT TextMaster Tests
----------------------


In order to run Functional Javascript Tests execute from docroot:
1. phantomjs --ssl-protocol=any --ignore-ssl-errors=true ../vendor/jcalderonzumba/gastonjs/src/Client/main.js 8510 1024 768 2>&1 >> /dev/null &
2. php core/scripts/run-tests.sh \
    --url http://your.site.com/ \
    --module tmgmt_textmaster --verbose --browser
    
See https://www.drupal.org/docs/8/phpunit/phpunit-javascript-testing-tutorial for more information.