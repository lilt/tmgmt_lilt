TMGMT Lilt Tests
---------------------
The companion [Lilt Drupal Environment](https://github.com/lilt/lilt_drupal_env)
repo contains a pre-configured Drupal environment in which to run tests on this
module.

Tests can be ran individually with the commands below:

- Code Standards: `ddev composer run-script code-standards`
- Deprecation Check: `ddev composer run-script deprecation-check`
- Functional JS: `ddev composer run-script functional-js`
- Lint: `ddev composer run-script lint`
- Unit Test: `ddev composer run-script unit-test`
