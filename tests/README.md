# Tests

To test, open *phpunit.xml* in your laravel installations root directory
and add to the `testsuites` section:

    <testsuite name="Application Test Suite">
        <directory>./vendor/crockett/csv-seeder/tests/</directory>
    </testsuite>

Then run `phpunit`