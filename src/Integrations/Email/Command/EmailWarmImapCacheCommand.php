<?php

namespace App\Integrations\Email\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Integrations\Email\EmailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'email:warm-imap-cache',
    description: 'Warm recent IMAP message cache for email profiles',
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
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Email profile key (requires --server)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Folder to warm', 'INBOX')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many recent days to warm', '7')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum messages per profile/folder', '200')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serverFilter = (string) ($input->getOption('server') ?? '');
        $profileFilter = (string) ($input->getOption('profile') ?? '');
        $folder = (string) ($input->getOption('folder') ?? 'INBOX');
        $days = (int) $input->getOption('days');
        $limit = (int) $input->getOption('limit');

        if ($profileFilter !== '' && $serverFilter === '') {
            $io->error('Option "--profile" requires "--server".');

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
            $io->warning('No servers with email profiles found.');

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
        $profilesProcessed = 0;
        $errors = 0;

        foreach ($servers as $server) {
            $emailProfiles = $this->emailProfileKeys($server, $profileFilter);
            if ($emailProfiles === []) {
                continue;
            }

            $this->serverContext->setServer($server);
            try {
                foreach ($emailProfiles as $profileKey) {
                    try {
                        $profileLabel = $server->name . '/' . $profileKey;
                        $progressCb = null;
                        if ($output->isVerbose()) {
                            $progressCb = function (array $event) use ($io, $output, $profileLabel): void {
                                $type = (string) ($event['type'] ?? '');
                                if ($type === 'cache_scan_done') {
                                    $io->writeln(sprintf(
                                        '  <comment>%s</comment> cache: %d hit, %d miss, %d total',
                                        $profileLabel,
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
                                            $profileLabel,
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
                                        $profileLabel,
                                        (int) ($event['downloaded'] ?? 0),
                                    ));
                                }
                            };
                        }

                        $result = $this->emailService->warmRecentCache(
                            profileId: $profileKey,
                            folder: $folder,
                            days: $days,
                            limit: $limit,
                            onProgress: $progressCb,
                        );

                        $profilesProcessed++;
                        $warmed = (int) ($result['warmed'] ?? 0);
                        $inspected = (int) ($result['inspected'] ?? 0);
                        $cached = (int) ($result['cached'] ?? max(0, $inspected - $warmed));
                        $warmedTotal += $warmed;
                        $inspectedTotal += $inspected;

                        $io->writeln(sprintf(
                            '<info>%s/%s</info> warmed=%d cached=%d inspected=%d folder=%s',
                            $server->name,
                            $profileKey,
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
                            $profileKey,
                            $e->getMessage(),
                        ));
                    }
                }
            } finally {
                $this->serverContext->clear();
            }
        }

        if ($profilesProcessed === 0 && $errors === 0) {
            $io->warning('No matching email profiles to warm.');

            return Command::SUCCESS;
        }

        $io->newLine();
        $io->definitionList(
            ['Profiles processed' => $profilesProcessed],
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

            return $server->hasProfileType('email') ? [$server] : [];
        }

        $servers = [];
        foreach ($this->configLoader->getServers() as $server) {
            if ($server->hasProfileType('email')) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * @return list<string>
     */
    private function emailProfileKeys(ServerConfig $server, string $profileFilter): array
    {
        $profiles = array_keys($server->getProfilesByType('email'));

        if ($profileFilter === '') {
            return $profiles;
        }

        return in_array($profileFilter, $profiles, true) ? [$profileFilter] : [];
    }
}
