<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\ContextBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'context',
    description: 'Build CONTEXT.md and manifest.json for a Loom, fetching it and extracting frames first when needed',
)]
class ContextCommand extends JsonCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('loom', InputArgument::REQUIRED, 'A Loom link or id, or a bundle directory already on disk')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where bundles are written', '.loom')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between tick frames', '4')
            ->addOption('scene', null, InputOption::VALUE_REQUIRED, 'Scene-cut sensitivity, 0 to 1', '0.25')
            ->addOption('max-frames', null, InputOption::VALUE_REQUIRED, 'Most frames to keep', '60')
            ->addOption(
                'cookies-from-browser',
                null,
                InputOption::VALUE_REQUIRED,
                'auto, none, or one of chrome, brave, edge, chromium, firefox, safari',
                'auto',
            )
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The password of a protected Loom')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Fetch and extract again, not reuse the bundle')
            ->addUsage(self::ExampleLoom)
            ->addUsage(sprintf('%s --refresh --max-frames 30', self::ExampleBundle))
            ->setHelp(
                'Prints the manifest as one JSON object on stdout; diagnostics go to stderr. '
                .'Exit codes: 2 bad link, 3 network, 4 auth, 5 yt-dlp, 6 ffmpeg.',
            );
    }

    protected function handle(InputInterface $input): array
    {
        return ContextBuilder::build($input->getArgument('loom'), $input->getOptions());
    }
}
