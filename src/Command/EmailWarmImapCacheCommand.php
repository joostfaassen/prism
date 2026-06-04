<?php

namespace App\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Email\EmailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'email:warm-imap-cache',
    description: 'Warm recent IMAP message cache for email accounts',
)]
class EmailWarmImapCacheCommand extends Command
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly EmailService $emailService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name from prism.config.yaml')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Email account key (requires --server)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Folder to warm', 'INBOX')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many recent days to warm', '7')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum messages per account/folder', '200')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serverFilter = (string) ($input->getOption('server') ?? '');
        $accountFilter = (string) ($input->getOption('account') ?? '');
        $folder = (string) ($input->getOption('folder') ?? 'INBOX');
        $days = (int) $input->getOption('days');
        $limit = (int) $input->getOption('limit');

        if ($accountFilter !== '' && $serverFilter === '') {
            $io->error('Option "--account" requires "--server".');

            return Command::INVALID;
        }

        if ($days < 1) {
            $io->error('Option "--days" must be at least 1.');

            return Command::INVALID;
        }

        if ($limit < 1) {
            $io->error('Option "--limit" must be at least 1.');

            return Command::INVALID;
        }

        $servers = $this->serversToProcess($serverFilter);
        if ($servers === []) {
            $io->warning('No servers with email accounts found.');

            return Command::SUCCESS;
        }

        $io->title('Warming email IMAP cache');
        $io->writeln(sprintf(
            'Folder: <info>%s</info> | Days: <info>%d</info> | Limit: <info>%d</info>',
            $folder,
            $days,
            $limit,
        ));

        $warmedTotal = 0;
        $inspectedTotal = 0;
        $accountsProcessed = 0;
        $errors = 0;

        foreach ($servers as $server) {
            $emailAccounts = $this->emailAccountKeys($server, $accountFilter);
            if ($emailAccounts === []) {
                continue;
            }

            $this->serverContext->setServer($server);
            try {
                foreach ($emailAccounts as $accountKey) {
                    try {
                        $accountLabel = $server->name . '/' . $accountKey;
                        $progressCb = null;
                        if ($output->isVerbose()) {
                            $progressCb = function (array $event) use ($io, $output, $accountLabel): void {
                                $type = (string) ($event['type'] ?? '');
                                if ($type === 'cache_scan_done') {
                                    $io->writeln(sprintf(
                                        '  <comment>%s</comment> cache: %d hit, %d miss, %d total',
                                        $accountLabel,
                                        (int) ($event['cached'] ?? 0),
                                        (int) ($event['missing'] ?? 0),
                                        (int) ($event['total'] ?? 0),
                                    ));

                                    return;
                                }

                                if ($type === 'download_uid') {
                                    if ($output->isVeryVerbose()) {
                                        $io->writeln(sprintf(
                                            '  <comment>%s</comment> downloading uid=%d (%d/%d)',
                                            $accountLabel,
                                            (int) ($event['uid'] ?? 0),
                                            (int) ($event['index'] ?? 0),
                                            (int) ($event['total'] ?? 0),
                                        ));
                                    }

                                    return;
                                }

                                if ($type === 'download_done' && $output->isVerbose()) {
                                    $io->writeln(sprintf(
                                        '  <comment>%s</comment> downloaded: %d',
                                        $accountLabel,
                                        (int) ($event['downloaded'] ?? 0),
                                    ));
                                }
                            };
                        }

                        $result = $this->emailService->warmRecentCache(
                            accountId: $accountKey,
                            folder: $folder,
                            days: $days,
                            limit: $limit,
                            onProgress: $progressCb,
                        );

                        $accountsProcessed++;
                        $warmed = (int) ($result['warmed'] ?? 0);
                        $inspected = (int) ($result['inspected'] ?? 0);
                        $cached = (int) ($result['cached'] ?? max(0, $inspected - $warmed));
                        $warmedTotal += $warmed;
                        $inspectedTotal += $inspected;

                        $io->writeln(sprintf(
                            '<info>%s/%s</info> warmed=%d cached=%d inspected=%d folder=%s',
                            $server->name,
                            $accountKey,
                            $warmed,
                            $cached,
                            $inspected,
                            (string) ($result['folder'] ?? $folder),
                        ));
                    } catch (\Throwable $e) {
                        $errors++;
                        $io->warning(sprintf(
                            '%s/%s failed: %s',
                            $server->name,
                            $accountKey,
                            $e->getMessage(),
                        ));
                    }
                }
            } finally {
                $this->serverContext->clear();
            }
        }

        if ($accountsProcessed === 0 && $errors === 0) {
            $io->warning('No matching email accounts to warm.');

            return Command::SUCCESS;
        }

        $io->newLine();
        $io->definitionList(
            ['Accounts processed' => $accountsProcessed],
            ['Messages warmed' => $warmedTotal],
            ['Messages inspected' => $inspectedTotal],
            ['Errors' => $errors],
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<ServerConfig>
     */
    private function serversToProcess(string $serverFilter): array
    {
        if ($serverFilter !== '') {
            $server = $this->configLoader->getServer($serverFilter);

            return $server->hasAccountType('email') ? [$server] : [];
        }

        $servers = [];
        foreach ($this->configLoader->getServers() as $server) {
            if ($server->hasAccountType('email')) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * @return list<string>
     */
    private function emailAccountKeys(ServerConfig $server, string $accountFilter): array
    {
        $accounts = array_keys($server->getAccountsByType('email'));

        if ($accountFilter === '') {
            return $accounts;
        }

        return in_array($accountFilter, $accounts, true) ? [$accountFilter] : [];
    }
}
