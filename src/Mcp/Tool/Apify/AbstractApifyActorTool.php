<?php

namespace App\Mcp\Tool\Apify;

use App\Apify\ApifyService;
use App\Mcp\Tool\ToolInterface;

/**
 * Base class for MCP tools that wrap a single Apify actor.
 *
 * This is the easy on-ramp for exposing an Apify actor as an MCP tool: extend
 * this class, declare the actor id, the tool name/description and the input
 * properties, and (optionally) map the MCP arguments onto the actor's input
 * and reshape its output. All the plumbing — account resolution, the
 * synchronous actor run, error handling and the MCP response envelope — is
 * handled here.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * How to add a new Apify-based tool (AI-coding-agent friendly):
 *
 *   1. Find the actor you want to expose (e.g. "apify/rag-web-browser").
 *   2. Inspect its metadata + input schema with the `apify_get_actor` tool
 *      (or `GET /v2/acts/{actorId}/builds/default`). Note the actor's input
 *      fields, their types and which are required, plus the shape of its
 *      dataset output.
 *   3. Create a new class in this directory extending AbstractApifyActorTool:
 *
 *        class ApifyMyActorTool extends AbstractApifyActorTool
 *        {
 *            protected function getActorId(): string
 *            {
 *                return 'username/actor-name';
 *            }
 *
 *            public function getName(): string
 *            {
 *                return 'apify_my_actor';
 *            }
 *
 *            public function getDescription(): string
 *            {
 *                return 'One-line, MCP-friendly description of what it does.';
 *            }
 *
 *            protected function getProperties(): array
 *            {
 *                return [
 *                    'query' => ['type' => 'string', 'description' => '...'],
 *                ];
 *            }
 *
 *            protected function getRequired(): array
 *            {
 *                return ['query'];
 *            }
 *
 *            // Optional: map MCP args -> actor input (default: pass-through).
 *            protected function buildActorInput(array $arguments): array
 *            {
 *                return ['query' => $arguments['query']];
 *            }
 *
 *            // Optional: reshape the dataset items into something compact.
 *            protected function transformOutput(array $items, array $arguments): mixed
 *            {
 *                return $items;
 *            }
 *        }
 *
 *   That's it — Symfony auto-discovers the tool via the ToolInterface tag and
 *   it becomes available on every server that has an `apify` account.
 * ───────────────────────────────────────────────────────────────────────────
 */
abstract class AbstractApifyActorTool implements ToolInterface
{
    /**
     * Argument keys that are handled by this base class and therefore never
     * forwarded as actor input by the default buildActorInput().
     */
    private const RESERVED_ARGUMENTS = ['account', 'max_items', 'memory', 'timeout', 'build'];

    public function __construct(
        protected readonly ApifyService $apifyService,
    ) {
    }

    /**
     * The Apify actor id, e.g. "apify/rag-web-browser" or "username~actor".
     */
    abstract protected function getActorId(): string;

    /**
     * Tool-specific input properties (JSON Schema), excluding the shared
     * `account` / run-option fields which are added automatically.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function getProperties(): array;

    /**
     * Names of required tool-specific properties.
     *
     * @return list<string>
     */
    protected function getRequired(): array
    {
        return [];
    }

    /**
     * Whether to expose the shared run-option inputs (max_items) on this tool.
     * Override and return false for actors where it makes no sense.
     */
    protected function exposeMaxItems(): bool
    {
        return true;
    }

    /**
     * Map the validated MCP arguments onto the actor's input JSON.
     *
     * Default: forward every argument except the reserved ones. Override to
     * rename fields, set defaults, or build a more complex input object.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function buildActorInput(array $arguments): array
    {
        $input = $arguments;
        foreach (self::RESERVED_ARGUMENTS as $reserved) {
            unset($input[$reserved]);
        }

        return $input;
    }

    /**
     * Run options forwarded to the actor (memory, timeout, maxItems, build).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    protected function getRunOptions(array $arguments): array
    {
        $options = [];

        if ($this->exposeMaxItems() && isset($arguments['max_items']) && $arguments['max_items'] !== '') {
            $options['maxItems'] = (int) $arguments['max_items'];
        }

        return $options;
    }

    /**
     * Normalize a tool argument into a list of non-empty strings.
     *
     * Apify's "stringList" inputs (usernames, URLs, hashtags, …) expect a JSON
     * array. MCP clients sometimes send a single string instead, so accept both
     * and always hand the actor a clean array.
     *
     * @return list<string>
     */
    protected function toStringList(mixed $value): array
    {
        if (is_array($value)) {
            $list = array_map(static fn ($v) => trim((string) $v), $value);

            return array_values(array_filter($list, static fn (string $v) => $v !== ''));
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return [];
    }

    /**
     * Reshape the actor's default dataset items before returning them over MCP.
     *
     * Default: return the items as-is. Override to trim large payloads, pick
     * relevant fields, or aggregate results into a more useful structure.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $arguments
     */
    protected function transformOutput(array $items, array $arguments): mixed
    {
        return $items;
    }

    public function getAccountType(): ?string
    {
        return 'apify';
    }

    public function getInputSchema(): array
    {
        $properties = [
            'account' => [
                'type' => 'string',
                'description' => 'Apify account key (from apify_list_accounts). Optional if only one account is configured.',
            ],
        ];

        $properties += $this->getProperties();

        if ($this->exposeMaxItems()) {
            $properties['max_items'] = [
                'type' => 'integer',
                'description' => 'Maximum number of result items to return from the actor run.',
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($this->getRequired()),
        ];
    }

    public function execute(array $arguments): array
    {
        try {
            $items = $this->apifyService->runActorSync(
                accountKey: $arguments['account'] ?? null,
                actorId: $this->getActorId(),
                input: $this->buildActorInput($arguments),
                options: $this->getRunOptions($arguments),
            );

            $output = $this->transformOutput($items, $arguments);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $output,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => sprintf(
                    'Error running Apify tool "%s": %s',
                    $this->getName(),
                    $e->getMessage(),
                )]],
                'isError' => true,
            ];
        }
    }
}
