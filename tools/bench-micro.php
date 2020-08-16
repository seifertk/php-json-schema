<?php

require __DIR__ . "/../vendor/autoload.php";

function run()
{
    $complex3 = json_decode(file_get_contents(__DIR__ . "/../spec/ajv/spec/tests/schemas/complex3.json"));
    $value = $complex3[0]->tests[0]->data;

    $n = 10000;
    $start = microtime(1);
    for ($i = 0; $i < $n; $i++) {
        $schema = \Swaggest\JsonSchema\Schema::import($complex3[0]->schema);
        $schema->in($value);
    }

    $elapsed = microtime(1) - $start;

    echo 'Finished in ' . $elapsed . ' seconds with ' . $n / $elapsed . " iterations per second, PHP ", PHP_VERSION . ".\n";
}

run();