<?php

declare(strict_types=1);

namespace LoomContext\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as BaseHelpCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony's help, which `<command> --help` relies on, with one change: `php loom help` and `php loom --help`
 * name no command of this tool, so they show the overview rather than a page about console plumbing.
 */
class HelpCommand extends BaseHelpCommand
{
    private ?Command $described = null;

    public function setCommand(Command $command): void
    {
        $this->described = $command;

        parent::setCommand($command);
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->described?->getName() ?? $input->getArgument('command_name');

        if (in_array($name, ['help', 'list'], true)) {
            return $this->getApplication()->find('list')->run(new ArrayInput([]), $output);
        }

        return parent::execute($input, $output);
    }
}
