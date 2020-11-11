TMGMT Lilt (tmgmt_lilt)
---------------------
TMGMT Lilt module is a plugin for Translation Management Tool module (tmgmt).
It uses Lilt (https://www.lilt.com) for automated translation of the content.

REQUIREMENTS
------------
This module requires TMGMT (http://drupal.org/project/tmgmt) module to be
installed. You will also need a working Lilt account & API key.

INSTALLATION
------------
This installation guide assumes you've already configured the Drupal core
multilingual modules as needed for your setup.

1. Install TMGMT (if not installed)
  - `composer require 'drupal/tmgmt:^1.11'` or tarball extraction
    to `modules/contrib`
  - `drush en tmgmt` or via `/admin/modules` admin UI.
2. Install Lilt TMGMT
  - `composer require 'drupal/tmgmt_lilt:^1.0'` or tarball extraction
    to `modules/contrib`
  - `drush en tmgmt_lilt` or via `/admin/modules` admin UI.
3. Config Lilt Provider
  - Via Drush:
    - `drush cset tmgmt.translator.lilt weight -20`
    - `drush cset tmgmt.translator.lilt settings.lilt_api_key "$LILT_API_KEY"`
  - Via UI:
    - Browse to `/admin/tmgmt/translators`
    - Drag priority to make **Lilt** the top provider.
    - Click **Save**
    - Click **Edit** for the **Lilt** provider
    - Add your **Lilt API key** and confirm access via the **Connect** button.
    - Click **Save**

USAGE
------------
Once installed, Lilt will be avaiable as a part of the TMGMT workflow.  See
the [TMGMT project page](https://www.drupal.org/project/tmgmt) for detailed
usage documentation.

Here's a short example usage scenario:

- Browse to `/admin/tmgmt/sources`
- Select content to translate.
- Set **Target language**.
- Click **Request translation**.
- Set **Provider** to **Lilt** (if not set).
- Add the **Due Date** of the Lilt translation project.
- Set the Lilt **Translation memory** for the project.
- Click **Submit to provider** to send the project to Lilt.
- Complete the TMGMT workflow once the translation has been completed on Lilt.

DEVELOPMENT
------------

Use the [GitHub project](http://github.com/lilt/tmgmt_lilt) for code
contributions, bug reports, feature requests, etc. If you'd like to contibute
to the module, you can use [this project](https://github.com/lilt/lilt_drupal_env)
as a self-contained development environment.
