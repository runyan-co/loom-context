<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\FramePuller;
use LoomContext\Region;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'frame',
    description: 'Pull and inspect selected frames from a Loom bundle',
)]
class FrameCommand extends JsonCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('bundle', InputArgument::REQUIRED, 'A bundle directory holding video.mp4')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Sample start-end, each in seconds or mm:ss')
            ->addOption('cue', null, InputOption::VALUE_REQUIRED, 'Caption text; pulls the frame a second after it')
            ->addOption('every', null, InputOption::VALUE_REQUIRED, 'Seconds between samples in a window', '0.5')
            ->addOption('max-frames', null, InputOption::VALUE_REQUIRED, 'Most frames to pull, up to 60', '60')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'Skip samples that repeat the one before')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Crop to x,y,width,height of the saved frame')
            ->addOption('zoom', null, InputOption::VALUE_REQUIRED, 'Enlarge the frame or region 1 to 4 times', '1')
            ->addUsage(sprintf('%s --window 00:10-00:14', self::ExampleBundle))
            ->addUsage(sprintf('%s --cue "save confirmation" --zoom 2', self::ExampleBundle))
            ->setHelp(
                'Prints one JSON object on stdout; diagnostics go to stderr. Give exactly one of --window or --cue. '
                .'Needs ffmpeg and ffprobe on PATH (exit 6 without).',
            );
    }

    protected function handle(InputInterface $input): array
    {
        $region = $input->getOption('region');

        return FramePuller::run(
            bundle: $input->getArgument('bundle'),
            window: $input->getOption('window'),
            cue: $input->getOption('cue'),
            every: (float) $input->getOption('every'),
            maxFrames: (int) $input->getOption('max-frames'),
            changedOnly: $input->getOption('changed-only'),
            region: $region === null ? null : Region::parse($region),
            zoom: (int) $input->getOption('zoom'),
        );
    }
}
