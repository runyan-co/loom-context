<?php

declare(strict_types=1);

namespace LoomContext\Console;

use LoomContext\ExitCode;
use LoomContext\Failure;
use LoomContext\Json;
use LoomContext\Log;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\ExceptionInterface as DotenvException;

/**
 * Whatever the command, the agent reading its output is promised the same thing: one JSON object as the last
 * stdout line, diagnostics on stderr, and a failure's own exit code.
 */
class Application extends BaseApplication
{
    /**
     * @param  string  $root  the folder holding the entry file, where an optional .env is read from
     */
    public function __construct(private string $root)
    {
        parent::__construct('loom-context');

        $this->addCommands([new ContextCommand, new FetchCommand, new FramesCommand]);

        // Failures are reported below as JSON rather than rendered by Symfony, and the entry file does the exiting.
        $this->setCatchExceptions(false);

        $this->setAutoExit(false);
    }

    /**
     * Symfony's own list, help and completion commands describe a generic console; these two describe this one.
     */
    protected function getDefaultCommands(): array
    {
        return [new HelpCommand, new ListCommand];
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput;

        // An agent runs this, never someone at a prompt: Symfony must not stop to ask "did you mean ...?".
        $input->setInteractive(false);

        try {
            $this->loadEnvironment();

            return parent::run($input, $output);
        } catch (Failure $failure) {
            return $this->report($failure);
        } catch (ExceptionInterface $exception) {
            // A mistyped option, a missing argument, or an unknown command is bad input like any other.
            return $this->report(new Failure(ExitCode::BadInput, $exception->getMessage()));
        }
    }

    /**
     * Settings may live in a .env beside the entry file. A variable already in the environment wins over it.
     */
    private function loadEnvironment(): void
    {
        $file = "{$this->root}/.env";

        if (! is_file($file)) {
            return;
        }

        try {
            (new Dotenv)->usePutenv()->load($file);
        } catch (DotenvException $exception) {
            throw new Failure(ExitCode::BadInput, "{$file} could not be read: {$exception->getMessage()}");
        }
    }

    private function report(Failure $failure): int
    {
        echo Json::encode([
            'error' => $failure->getMessage(),
            'detail' => $failure->detail,
            'exit' => $failure->exit->value,
        ]), "\n";

        Log::line(
            $failure->detail === ''
                ? $failure->getMessage()
                : sprintf('%s (%s)', $failure->getMessage(), $failure->detail),
        );

        return $failure->exit->value;
    }
}
