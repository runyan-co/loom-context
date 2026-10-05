<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class JsonCommand extends Command
{
    // A made-up recording, for the examples each command registers.
    protected const ExampleLoom = 'https://www.loom.com/share/0123456789abcdef0123456789abcdef';

    protected const ExampleBundle = '.loom/0123456789abcdef0123456789abcdef';

    /**
     * @return array<string, mixed>
     */
    abstract protected function handle(InputInterface $input): array;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Raw, so console formatting never rewrites the JSON.
        $output->writeln(Json::encode($this->handle($input)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
