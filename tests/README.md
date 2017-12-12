TMGMT TextMaster Tests
----------------------


In order to run Functional Javascript Tests execute from docroot:
1. phantomjs --ssl-protocol=any \
 --ignore-ssl-errors=true \
 ../vendor/jcalderonzumba/gastonjs/src/Client/main.js \
  8510 1024 768 2>&1 >> /dev/null &
2. (optional)
  export CREATE_TEST_SCREENSHOTS=1
3. php core/scripts/run-tests.sh \
    --url http://your.site.com/ \
    --module tmgmt_textmaster --verbose --browser
    
See https://www.drupal.org/docs/8/phpunit/phpunit-javascript-testing-tutorial
for more information.

To create screenshots during the test run please set 
environment variable CREATE_SCREENSHOTS=1 ("export CREATE_TEST_SCREENSHOTS=1"
in Linux). In this case after test run you will be able to check screenshots 
of basic test steps in directory sites/simpletest/tmgmt_textmaster/
