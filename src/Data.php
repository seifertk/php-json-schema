<?php

namespace Swaggest\JsonSchema;

class Data
{
    public function __construct($ref)
    {
        $this->data = $ref;
    }

    /** @var string */
    public $data;
}