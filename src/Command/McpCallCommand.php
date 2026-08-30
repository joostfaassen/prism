<?php

namespace App\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Mcp\McpHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mcp:call', description: 'Call an MCP tool for a server (CLI harness)')]
class McpCallCommand extends Command
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly McpHandler $mcpHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name from prism config')
            ->addArgument('tool', InputArgument::REQUIRED, 'MCP tool name')
            ->addOption(
                'arg',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Tool argument as key=value (repeatable). JSON values are decoded.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serverName = (string) $input->getArgument('server');
        $toolName = (string) $input->getArgument('tool');

        try {
            $server = $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->serverContext->setServer($server);

        try {
            $arguments = $this->parseArgs((array) $input->getOption('arg'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $response = $this->mcpHandler->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ]);

        $encoded = json_encode($this->summarizeForCli($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $io->writeln($encoded ?: '');

        if (isset($response['error']) || (($response['result']['isError'] ?? false) === true)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rawArgs
     *
     * @return array<string, mixed>
     */
    private function parseArgs(array $rawArgs): array
    {
        $arguments = [];

        foreach ($rawArgs as $raw) {
            $eq = strpos($raw, '=');
            if ($eq === false) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid --arg "%s". Use key=value.',
                    $raw,
                ));
            }

            $key = substr($raw, 0, $eq);
            $value = substr($raw, $eq + 1);
            if ($key === '') {
                throw new \InvalidArgumentException('Argument key must not be empty.');
            }

            $arguments[$key] = $this->decodeArgValue($value);
        }

        return $arguments;
    }

    private function decodeArgValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function summarizeForCli(array $response): array
    {
        $result = $response['result'] ?? null;
        if (!is_array($result) || !isset($result['content']) || !is_array($result['content'])) {
            return $response;
        }

        $content = [];
        foreach ($result['content'] as $part) {
            if (!is_array($part)) {
                $content[] = $part;
                continue;
            }

            if (($part['type'] ?? '') === 'image' && isset($part['data']) && is_string($part['data'])) {
                $content[] = [
                    'type' => 'image',
                    'mimeType' => $part['mimeType'] ?? null,
                    'bytes_b64' => strlen($part['data']),
                    'data' => '[base64 omitted, ' . strlen($part['data']) . ' chars]',
                ];
                continue;
            }

            $content[] = $part;
        }

        $result['content'] = $content;
        $response['result'] = $result;

        return $response;
    }
}
