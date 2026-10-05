<?php

declare(strict_types=1);

namespace LoomContext\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What `php loom` prints: each registered command with the line that runs it and an example of it, in place
 * of Symfony's generic "command [options] [arguments]" screen.
 */
#[AsCommand(name: 'list', description: 'Show what this tool can do', hidden: true)]
class ListCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commands = array_filter($this->getApplication()->all(), fn (Command $command) => ! $command->isHidden());

        ksort($commands);

        $lines = ['loom-context: turn a Loom recording into context an agent can read', ''];

        foreach ($commands as $command) {
            array_push($lines, self::runLine($command), "      {$command->getDescription()}");

            foreach ($command->getUsages() as $example) {
                $lines[] = "      e.g. php loom {$example}";
            }

            $lines[] = '';
        }

        $lines[] = 'Add --help to a command for its options, e.g. php loom context --help';

        // Raw, so the angle brackets around an argument's name are not read as console formatting.
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private static function runLine(Command $command): string
    {
        $arguments = array_map(
            fn (InputArgument $argument) => "<{$argument->getName()}>",
            $command->getDefinition()->getArguments(),
        );

        return sprintf('  php loom %s %s [options]', $command->getName(), implode(' ', $arguments));
    }
}
