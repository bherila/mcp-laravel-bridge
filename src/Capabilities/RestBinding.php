<?php

namespace Bherila\McpLaravelBridge\Capabilities;

final readonly class RestBinding
{
    /**
     * @param  list<string>  $pathParameters
     * @param  list<int>  $successStatuses
     * @param  list<string>  $requestContentTypes
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $routeName = null,
        public array $pathParameters = [],
        public array $successStatuses = [200],
        public array $requestContentTypes = ['application/json'],
    ) {}
}
