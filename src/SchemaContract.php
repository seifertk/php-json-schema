<?php

namespace Swaggest\JsonSchema;


use Swaggest\JsonSchema\Constraint\Properties;

interface SchemaContract
{
    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     */
    public function process($data, Context $context);

    /**
     * @param mixed $data
     * @param Context|null $options
     * @throws InvalidValue
     * @return array|mixed|null|object|\stdClass
     */
    public function in($data, Context $options = null);

    /**
     * @param mixed $data
     * @param Context|null $context
     * @throws InvalidValue
     * @return array|mixed|null|object|\stdClass
     */
    public function out($data, Context $context = null);

    /**
     * @return mixed
     */
    public function getDefault();

    /** @return null|Properties|Schema[] */
    public function getProperties();

    /**
     * @param Context|null $options
     * @return Structure\ObjectItemContract
     */
    public function makeObjectItem(Context $options = null);

    /**
     * @return string
     */
    public function getObjectItemClass();

    /**
     * @return string[]
     */
    public function getPropertyNames();

    /**
     * @return string[]
     */
    public function getNestedPropertyNames();

}