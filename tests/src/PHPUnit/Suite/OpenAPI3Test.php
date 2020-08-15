<?php


namespace Swaggest\JsonSchema\Tests\PHPUnit\Suite;


use Swaggest\JsonSchema\Schema;

class OpenAPI3Test extends \PHPUnit\Framework\TestCase
{
    public function testValidate() {
        $schema = Schema::import(json_decode(file_get_contents(__DIR__ . '/../../../resources/oai3.json')));
        $instance = $schema->in(json_decode(file_get_contents(__DIR__ . '/../../../resources/petstore.json')));
        $this->assertTrue(true);
    }

}