<?php

namespace Knuckles\Scribe\Writing;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Knuckles\Camel\Camel;
use Knuckles\Camel\Output\EndpointData;
use Knuckles\Camel\Output\Group;
use Knuckles\Camel\Output\Parameter;
use Knuckles\Scribe\Extracting\ParamHelpers;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Tools\Utils;

class OpenAPISpecWriter
{
    use ParamHelpers;

    const VERSION = '3.0.3';

    /**
     * @var DocumentationConfig
     */
    private $config;

    /**
     * Object to represent empty values, since empty arrays get serialised as objects.
     * Can't use a constant because of initialisation expression.
     *
     * @var \stdClass
     */
    public $EMPTY;

    public function __construct(DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $this->EMPTY = new \stdClass();
    }

    /**
     * See https://swagger.io/specification/
     *
     * @param array[] $groupedEndpoints
     *
     * @return array
     */
    public function generateSpecContent(array $groupedEndpoints)
    {
        return array_merge([
            'openapi' => self::VERSION,
            'info' => [
                'title' => $this->config->get('title') ?: config('app.name', ''),
                'description' => $this->config->get('description', ''),
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => rtrim($this->config->get('base_url') ?? config('app.url'), '/'),
                ],
            ],
            'paths' => $this->generatePathsSpec($groupedEndpoints),
        ], $this->generateSecurityPartialSpec());
    }

    /**
     * @param array[] $groupedEndpoints
     *
     * @return mixed
     */
    protected function generatePathsSpec(array $groupedEndpoints)
    {
        $allEndpoints = collect($groupedEndpoints)->map->endpoints->flatten(1);
        // OpenAPI groups endpoints by path, then method
        $groupedByPath = $allEndpoints->groupBy(function ($endpoint) {
            $path = str_replace("?}", "}", $endpoint->uri); // Remove optional parameters indicator in path
            return '/' . ltrim($path, '/');
        });
        return $groupedByPath->mapWithKeys(function (Collection $endpoints, $path) use ($groupedEndpoints) {
            $operations = $endpoints->mapWithKeys(function (EndpointData $endpoint) use ($groupedEndpoints) {
                $spec = [
                    'summary' => $endpoint->metadata->title,
                    'description' => $endpoint->metadata->description,
                    'parameters' => $this->generateEndpointParametersSpec($endpoint),
                    'responses' => $this->generateEndpointResponsesSpec($endpoint),
                    'tags' => [Arr::first($groupedEndpoints, fn($group) => Camel::doesGroupContainEndpoint($group, $endpoint))['name']],
                ];

                if (count($endpoint->bodyParameters)) {
                    $spec['requestBody'] = $this->generateEndpointRequestBodySpec($endpoint);
                }

                if (!$endpoint->metadata->authenticated) {
                    // Make sure to exclude non-auth endpoints from auth
                    $spec['security'] = [];
                }

                return [strtolower($endpoint->methods[0]) => $spec];
            });

            $pathItem = $operations;

            // Placing all URL parameters at the path level, since it's the same path anyway
            if (count($endpoints[0]->urlParameters)) {
                $parameters = [];
                /**
                 * @var string $name
                 * @var Parameter $details
                 */
                foreach ($endpoints[0]->urlParameters as $name => $details) {
                    $parameterData = [
                        'in' => 'path',
                        'name' => $name,
                        'description' => $details->description,
                        'example' => $details->example,
                        // Currently, Swagger requires path parameters to be required
                        'required' => true,
                        'schema' => [
                            'type' => $details->type,
                        ],
                    ];
                    // Workaround for optional parameters
                    if (empty($details->required)) {
                        $parameterData['description'] = rtrim('Optional parameter. ' . $parameterData['description']);
                        $parameterData['examples'] = [
                            'omitted' => [
                                'summary' => 'When the value is omitted',
                                'value' => '',
                            ],
                        ];

                        if ($parameterData['example'] !== null) {
                            $parameterData['examples']['present'] = [
                                'summary' => 'When the value is present',
                                'value' => $parameterData['example'],
                            ];
                        }

                        // Can't have `example` and `examples`
                        unset($parameterData['example']);
                    }
                    $parameters[] = $parameterData;
                }
                $pathItem['parameters'] = $parameters;
            }

            return [$path => $pathItem];
        })->toArray();
    }

    /**
     * Add query parameters and headers.
     *
     * @param EndpointData $endpoint
     *
     * @return array
     */
    protected function generateEndpointParametersSpec(EndpointData $endpoint): array
    {
        $parameters = [];

        if (count($endpoint->queryParameters)) {
            /**
             * @var string $name
             * @var Parameter $details
             */
            foreach ($endpoint->queryParameters as $name => $details) {
                $parameterData = [
                    'in' => 'query',
                    'name' => $name,
                    'description' => $details->description,
                    'example' => $details->example,
                    'required' => $details->required,
                    'schema' => $this->generateFieldData($details),
                ];
                $parameters[] = $parameterData;
            }
        }

        if (count($endpoint->headers)) {
            foreach ($endpoint->headers as $name => $value) {
                $parameters[] = [
                    'in' => 'header',
                    'name' => $name,
                    'description' => '',
                    'example' => $value,
                    'schema' => [
                        'type' => 'string',
                    ],
                ];
            }
        }

        return $parameters;
    }

    protected function generateEndpointRequestBodySpec(EndpointData $endpoint)
    {
        $body = [];

        if (count($endpoint->bodyParameters)) {
            $schema = [
                'type' => 'object',
                'properties' => [],
            ];

            $hasRequiredParameter = false;
            $hasFileParameter = false;

            foreach ($endpoint->nestedBodyParameters as $name => $details) {
                if ($details['required']) {
                    $hasRequiredParameter = true;
                    // Don't declare this earlier.
                    // Can't have an empty `required` array. Must have something there.
                    $schema['required'][] = $name;
                }


                if ($details['type'] === 'file') {
                    $hasFileParameter = true;
                }

                $fieldData = $this->generateFieldData($details);

                $schema['properties'][$name] = $fieldData;
            }

            $body['required'] = $hasRequiredParameter;

            if ($hasFileParameter) {
                // If there are file parameters, content type changes to multipart
                $contentType = 'multipart/form-data';
            } elseif (isset($endpoint->headers['Content-Type'])) {
                $contentType = $endpoint->headers['Content-Type'];
            } else {
                $contentType = 'application/json';
            }

            $body['content'][$contentType]['schema'] = $schema;

        }

        // return object rather than empty array, so can get properly serialised as object
        return count($body) > 0 ? $body : $this->EMPTY;
    }

    protected function generateEndpointResponsesSpec(EndpointData $endpoint)
    {
        // See https://swagger.io/docs/specification/describing-responses/
        $responses = [];

        foreach ($endpoint->responses as $response) {
            // OpenAPI groups responses by status code
            // Only one response type per status code, so only the last one will be used
            if (intval($response->status) === 204) {
                // Must not add content for 204
                $responses[204] = [
                    'description' => $this->getResponseDescription($response),
                ];
            } else {
                $responses[$response->status] = [
                    'description' => $this->getResponseDescription($response),
                    'content' => $this->generateResponseContentSpec($response->content, $endpoint),
                ];
            }
        }

        // return object rather than empty array, so can get properly serialised as object
        return count($responses) > 0 ? $responses : $this->EMPTY;
    }

    protected function getResponseDescription($response)
    {
        if (Str::startsWith($response->content, "<<binary>>")) {
            return trim(str_replace("<<binary>>", "", $response->content));
        }

        return strval($response->description);
    }

    protected function generateResponseContentSpec(?string $responseContent, EndpointData $endpoint)
    {
        if (Str::startsWith($responseContent, '<<binary>>')) {
            return [
                'application/octet-stream' => [
                    'schema' => [
                        'type' => 'string',
                        'format' => 'binary',
                    ],
                ],
            ];
        }

        if ($responseContent === null) {
            return [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        // See https://swagger.io/docs/specification/data-models/data-types/#null
                        'nullable' => true,
                    ],
                ],
            ];
        }

        $decoded = json_decode($responseContent);
        if ($decoded === null) { // Decoding failed, so we return the content string as is
            return [
                'text/plain' => [
                    'schema' => [
                        'type' => 'string',
                        'example' => $responseContent,
                    ],
                ],
            ];
        }

        switch ($type = gettype($decoded)) {
            case 'string':
            case 'boolean':
            case 'integer':
            case 'double':
                return [
                    'application/json' => [
                        'schema' => [
                            'type' => $type === 'double' ? 'number' : $type,
                            'example' => $decoded,
                        ],
                    ],
                ];

            case 'array':
                if (!count($decoded)) {
                    // empty array
                    return [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object', // No better idea what to put here
                                ],
                                'example' => $decoded,
                            ],
                        ],
                    ];
                }

                // Non-empty array
                return [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => $this->convertScribeOrPHPTypeToOpenAPIType(gettype($decoded[0])),
                            ],
                            'example' => $decoded,
                        ],
                    ],
                ];

            case 'object':
                $properties = collect($decoded)->mapWithKeys(function ($value, $key) use ($endpoint) {
                    $spec = [
                        // Note that we aren't recursing for nested objects. We stop at one level.
                        'type' => $this->convertScribeOrPHPTypeToOpenAPIType(gettype($value)),
                        'example' => $value,

                    ];
                    if (isset($endpoint->responseFields[$key]->description)) {
                        $spec['description'] = $endpoint->responseFields[$key]->description;
                    }
                    if ($spec['type'] === 'array' && !empty($value)) {
                        $spec['items']['type'] = $this->convertScribeOrPHPTypeToOpenAPIType(gettype($value[0]));
                    }

                    return [
                        $key => $spec,
                    ];
                })->toArray();

                if (!count($properties)) {
                    $properties = $this->EMPTY;
                }

                return [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'example' => $decoded,
                            'properties' => $properties,
                        ],
                    ],
                ];
        }
    }

    protected function generateSecurityPartialSpec()
    {
        $isApiAuthed = $this->config->get('auth.enabled', false);
        if (!$isApiAuthed) {
            return [];
        }

        $location = $this->config->get('auth.in');
        $parameterName = $this->config->get('auth.name');
        $scheme = [];

        switch ($location) {
            case 'query':
            case 'header':
                $scheme = [
                    'type' => 'apiKey',
                    'name' => $parameterName,
                    'in' => $location,
                    'description' => '',
                ];
                break;

            case 'bearer':
            case 'basic':
                $scheme = [
                    'type' => 'http',
                    'scheme' => $location,
                    'description' => '',
                ];
                break;
            // OpenAPI doesn't support auth with body parameter
        }

        return [
            // All security schemes must be registered in `components.securitySchemes`...
            'components' => [
                'securitySchemes' => [
                    // 'default' is an arbitrary name for the auth scheme. Can be anything, really.
                    'default' => $scheme,
                ],
            ],
            // ...and then can be applied in `security`
            'security' => [
                [
                    'default' => [],
                ],
            ],
        ];
    }

    protected function convertScribeOrPHPTypeToOpenAPIType($type)
    {
        switch ($type) {
            case 'float':
            case 'double':
                return 'number';
            case 'NULL':
                // null is not an allowed type in OpenAPI
                return 'string';
            default:
                return $type;
        }
    }

    /**
     * @param Parameter|array $field
     *
     * @return array
     */
    public function generateFieldData($field): array
    {
        if (is_array($field)) {
            $field = new Parameter($field);
        }

        if ($field->type === 'file') {
            // See https://swagger.io/docs/specification/describing-request-body/file-upload/
            return [
                'type' => 'string',
                'format' => 'binary',
                'description' => $field->description ?: '',
            ];
        } else if (Utils::isArrayType($field->type)) {
            $baseType = Utils::getBaseTypeFromArrayType($field->type);
            $fieldData = [
                'type' => 'array',
                'description' => $field->description ?: '',
                'example' => $field->example,
                'items' => Utils::isArrayType($baseType)
                    ? $this->generateFieldData([
                        'name' => '',
                        'type' => $baseType,
                        'value' => ($field->example ?: [null])[0],
                    ])
                    : ['type' => $baseType],
            ];

            if ($baseType === 'object' && !empty($field->__fields)) {
                foreach ($field->__fields as $fieldSimpleName => $subfield) {
                    $fieldData['items']['properties'][$fieldSimpleName] = $this->generateFieldData($subfield);
                    if ($subfield['required']) {
                        $fieldData['items']['required'][] = $fieldSimpleName;
                    }
                }
            }

            return $fieldData;
        } else if ($field->type === 'object') {
            return [
                'type' => 'object',
                'description' => $field->description ?: '',
                'example' => $field->example,
                'properties' => collect($field->__fields)->mapWithKeys(function ($subfield, $subfieldName) {
                    return [$subfieldName => $this->generateFieldData($subfield)];
                })->all(),
            ];
        } else {
            return [
                'type' => $this->normalizeTypeName($field->type),
                'description' => $field->description ?: '',
                'example' => $field->example,
            ];
        }
    }
}
