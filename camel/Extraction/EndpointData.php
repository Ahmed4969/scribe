<?php

namespace Knuckles\Camel\Extraction;

use Illuminate\Routing\Route;
use Knuckles\Camel\BaseDTO;
use Knuckles\Scribe\Tools\Utils as u;
use ReflectionClass;
use ReflectionFunctionAbstract;


class EndpointData extends BaseDTO
{
    /**
     * @var array<string>
     */
    public array $methods;

    public string $uri;

    public Metadata $metadata;

    /**
     * @var array<string,string>
     */
    public array $headers = [];

    /**
     * @var array<string,\Knuckles\Camel\Extraction\Parameter>
     */
    public array $urlParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanUrlParameters = [];

    /**
     * @var array<string,\Knuckles\Camel\Extraction\Parameter>
     */
    public array $queryParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanQueryParameters = [];

    public array $bodyParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanBodyParameters = [];

    /**
     * @var array<string,\Illuminate\Http\UploadedFile|array>
     */
    public array $fileParameters = [];

    /**
     * @var ResponseCollection|array
     */
    public $responses;

    /**
     * @var array<string,\Knuckles\Camel\Extraction\ResponseField>
     */
    public array $responseFields = [];

    /**
     * Authentication info for this endpoint. In the form [{where}, {name}, {sample}]
     * Example: ["query", "api_key", "njiuyiw97865rfyvgfvb1"]
     */
    public array $auth = [];

    public ?ReflectionClass $controller;

    public ?ReflectionFunctionAbstract $method;

    public Route $route;

    public function __construct(array $parameters = [])
    {
        $parameters['metadata'] = $parameters['metadata'] ?? new Metadata([]);
        $parameters['responses'] = $parameters['responses'] ?? new ResponseCollection([]);

        parent::__construct($parameters);
    }

    public static function fromRoute(Route $route, array $extras = []): self
    {
        // $this->id = md5($this->getUri($route) . ':' . implode($this->getMethods($route))),
        $methods = self::getMethods($route);
        $uri = $route->uri();

        [$controllerName, $methodName] = u::getRouteClassAndMethodNames($route);
        $controller = new ReflectionClass($controllerName);
        $method = u::getReflectedRouteMethod([$controllerName, $methodName]);

        $data = compact('methods', 'uri', 'controller', 'method', 'route');
        $data = array_merge($data, $extras);

        return new EndpointData($data);
    }

    /**
     * @param Route $route
     *
     * @return array<string>
     */
    public static function getMethods(Route $route): array
    {
        $methods = $route->methods();

        // Laravel adds an automatic "HEAD" endpoint for each GET request, so we'll strip that out,
        // but not if there's only one method (means it was intentional)
        if (count($methods) === 1) {
            return $methods;
        }

        return array_diff($methods, ['HEAD']);
    }

    public function name()
    {
        return sprintf("[%s] {$this->route->uri}.", implode(',', $this->route->methods));
    }

    public function endpointId()
    {
        return $this->methods[0] . str_replace(['/', '?', '{', '}', ':'], '-', $this->uri);
    }

    /**
     * Prepare the endpoint data for serialising.
     */
    public function forOutput(): \Knuckles\Camel\Output\EndpointData
    {
        $this->metadata = $this->metadata->except('groupName', 'groupDescription');
        return new \Knuckles\Camel\Output\EndpointData($this->except(
            // Get rid of all duplicate data
            'cleanQueryParameters', 'cleanUrlParameters', 'fileParameters', 'cleanBodyParameters',
            // and objects only needed for extraction
            'route', 'controller', 'method',
        )->toArray());
    }
}