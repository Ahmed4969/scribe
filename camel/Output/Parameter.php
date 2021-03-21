<?php

namespace Knuckles\Camel\Output;


use Knuckles\Camel\BaseDTO;

class Parameter extends \Knuckles\Camel\Extraction\Parameter
{
    public string $name;
    public ?string $description = null;
    public bool $required = false;
    public $example = null;
    public string $type = 'string';
    public array $__fields = [];
}
