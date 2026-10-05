<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\FrameExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'frames',
    description: 'Extract a small, de-duplicated set of screenshots from a bundle, aligned to its narration',
)]
class FramesCommand extends JsonCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('bundle', InputArgument::REQUIRED, 'A bundle directory holding video.mp4')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between tick frames', '4')
            ->addOption('scene', null, InputOption::VALUE_REQUIRED, 'Scene-cut sensitivity, 0 to 1', '0.25')
            ->addOption('max-frames', null, InputOption::VALUE_REQUIRED, 'Most frames to keep', '60')
            ->addOption('long-edge', null, InputOption::VALUE_REQUIRED, 'Longest side of a frame, in pixels', '1568')
            ->addOption('no-dedupe', null, InputOption::VALUE_NONE, 'Keep frames that repeat the one before')
            ->addUsage(sprintf('%s --interval 2', self::ExampleBundle))
            ->setHelp(
                'Prints one JSON object on stdout; diagnostics go to stderr. '
                .'Needs ffmpeg and ffprobe on PATH (exit 6 without).',
            );
    }

    protected function handle(InputInterface $input): array
    {
        $summary = FrameExtractor::run(
            $input->getArgument('bundle'),
            (float) $input->getOption('interval'),
            (float) $input->getOption('scene'),
            (int) $input->getOption('max-frames'),
            (int) $input->getOption('long-edge'),
            ! $input->getOption('no-dedupe'),
        );

        unset($summary['frames']);

        return $summary;
    }
}
