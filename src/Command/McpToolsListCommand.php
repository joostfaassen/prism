<?php

namespace App\Command;

use App\Mcp\McpHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:tools', description: 'List all registered MCP tools (name + account type), sorted')]
class McpToolsListCommand extends Command
{
    public function __construct(
        private readonly McpHandler $mcpHandler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lines = [];
        foreach ($this->mcpHandler->getTools() as $tool) {
            $lines[] = $tool->getName() . "\t" . ($tool->getAccountType() ?? '-');
        }
        sort($lines);
        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}
