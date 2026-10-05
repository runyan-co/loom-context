<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\Fetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'fetch',
    description: 'Fetch a Loom into a bundle directory: metadata, chapters, transcript, MP4',
)]
class FetchCommand extends JsonCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('loom', InputArgument::REQUIRED, 'A Loom link or id')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where bundles are written', '.loom')
            ->addOption(
                'cookies-from-browser',
                null,
                InputOption::VALUE_REQUIRED,
                'auto, none, or one of chrome, brave, edge, chromium, firefox, safari',
                'auto',
            )
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The password of a protected Loom')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Fetch again instead of reusing the bundle')
            ->addOption('skip-video', null, InputOption::VALUE_NONE, 'Leave the MP4 alone')
            ->addUsage(sprintf('%s --skip-video', self::ExampleLoom))
            ->setHelp(
                'Prints one JSON object on stdout; diagnostics go to stderr. '
                .'Exit codes: 2 bad link, 3 network, 4 auth, 5 yt-dlp.',
            );
    }

    protected function handle(InputInterface $input): array
    {
        return (new Fetcher)->fetch(
            $input->getArgument('loom'),
            $input->getOption('out'),
            $input->getOption('cookies-from-browser'),
            $input->getOption('password'),
            $input->getOption('refresh'),
            $input->getOption('skip-video'),
        );
    }
}
