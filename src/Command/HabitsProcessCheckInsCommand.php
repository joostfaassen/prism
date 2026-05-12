<?php

namespace App\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Habits\HabitsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'habits:process-check-ins', description: 'Mark overdue habit check-ins as missed and apply configured penalties')]
class HabitsProcessCheckInsCommand extends Command
{
    public function __construct(
        private readonly HabitsService $habitsService,
        private readonly PrismConfigLoader $prismConfigLoader,
        private readonly ServerContext $serverContext,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $total = 0;
        foreach ($this->prismConfigLoader->getServers() as $server) {
            if (!$server->hasAccountType('habits')) {
                continue;
            }
            $this->serverContext->setServer($server);
            try {
                $n = $this->habitsService->processExpiredCheckIns();
                if ($n > 0) {
                    $io->writeln(sprintf('%s: closed %d missed check-in(s)', $server->name, $n));
                }
                $total += $n;
            } finally {
                $this->serverContext->clear();
            }
        }

        $io->success(sprintf('Total missed check-ins processed: %d', $total));

        return Command::SUCCESS;
    }
}
