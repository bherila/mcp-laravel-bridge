<?php

namespace Bherila\McpLaravelBridge\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** @implements RequestHandlerInterface<CallToolResult> */
final readonly class ValidatedCallToolHandler implements RequestHandlerInterface
{
    /** @param array<string, string> $schemaIds */
    public function __construct(
        private CallToolHandler $inner,
        private RegistryInterface $registry,
        private SchemaValidator $validator,
        private array $schemaIds,
        private LoggerInterface $logger,
        private string $failureMessage = 'The API returned a response that failed its output contract.',
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $response = $this->inner->handle($request, $session);
        if (! $request instanceof CallToolRequest || ! $response instanceof Response) {
            return $response;
        }
        $result = $response->result;
        // SDK 0.9 can also answer with an InputRequiredResult, which is not tool output.
        if (! $result instanceof CallToolResult) {
            return $response;
        }
        if ($this->isSdkOutputRejection($result, $request->name)) {
            return $this->refuse($request, ['output-schema']);
        }
        if ($result->isError || $result->structuredContent === null) {
            return $response;
        }

        try {
            $errors = $this->validator->validateAgainstJsonSchema(
                $result->structuredContent,
                $this->registry->getTool($request->name)->tool->outputSchema,
            );
            $keywords = array_map(static fn (array $error): string => $error['keyword'], $errors);
        } catch (Throwable) {
            $keywords = ['validation-unavailable'];
        }

        if ($keywords === []) {
            return $response;
        }

        return $this->refuse($request, $keywords);
    }

    /**
     * SDK 0.9 validates output before this handler sees it, and answers a mismatch with
     * an error result that tells the client which properties failed and how. Only the
     * text identifies that result, so a test pins it against the SDK this package allows.
     */
    private function isSdkOutputRejection(CallToolResult $result, string $tool): bool
    {
        $first = $result->content[0] ?? null;

        return $result->isError
            && $first instanceof TextContent
            && str_starts_with((string) $first->text, "Invalid structured output for tool '{$tool}': ");
    }

    /** @param list<string> $keywords */
    private function refuse(CallToolRequest $request, array $keywords): Error
    {
        $this->logger->warning('Agent MCP tool result did not match its output schema.', [
            'tool' => $request->name,
            'schema' => $this->schemaIds[$request->name] ?? 'unknown',
            'keywords' => array_values(array_unique($keywords)),
        ]);

        return Error::forInternalError($this->failureMessage, $request->getId());
    }
}
