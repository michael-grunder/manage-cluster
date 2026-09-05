<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ConsoleOutput
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $hasEphemeralLine = false;

    /**
     * @var (callable(ConsoleOutputLevel, string): void)|null
     */
    private $sink = null;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly ?bool $interactive = null,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * Divert every write to a sink instead of the underlying streams. A
     * fullscreen renderer owns the terminal while it runs, so anything the
     * command would otherwise print has to be captured rather than smeared
     * across the drawn frame. Passing null restores stream writes.
     *
     * @param (callable(ConsoleOutputLevel, string): void)|null $sink
     */
    public function redirectTo(?callable $sink): void
    {
        $this->clearEphemeralLine();
        $this->sink = $sink;
    }

    public function isInteractive(): bool
    {
        if (is_bool($this->interactive)) {
            return $this->interactive;
        }

        return function_exists('stream_isatty') && stream_isatty($this->stdout);
    }

    public function step(string $message): void
    {
        $this->writeStdoutLine(
            level: ConsoleOutputLevel::Step,
            interactivePrefix: $this->decorate('cyan', 'bold', '●'),
            plainPrefix: '[..]',
            message: $message,
        );
    }

    public function info(string $message): void
    {
        $this->writeStdoutLine(
            level: ConsoleOutputLevel::Info,
            interactivePrefix: $this->decorate('blue', 'bold', 'ℹ'),
            plainPrefix: '[i]',
            message: $message,
        );
    }

    public function success(string $message): void
    {
        $this->writeStdoutLine(
            level: ConsoleOutputLevel::Success,
            interactivePrefix: $this->decorate('green', 'bold', '✓'),
            plainPrefix: '[ok]',
            message: $message,
        );
    }

    public function warning(string $message): void
    {
        $this->writeStdoutLine(
            level: ConsoleOutputLevel::Warning,
            interactivePrefix: $this->decorate('yellow', 'bold', '!'),
            plainPrefix: '[!]',
            message: $message,
        );
    }

    public function error(string $message): void
    {
        $this->writeStderrLine(
            level: ConsoleOutputLevel::Error,
            interactivePrefix: $this->decorate('red', 'bold', '✗'),
            plainPrefix: '[error]',
            message: $message,
        );
    }

    public function detail(string $label, string $value): void
    {
        if ($this->sink !== null) {
            ($this->sink)(ConsoleOutputLevel::Detail, sprintf('%s: %s', $label, $value));

            return;
        }

        $formattedLabel = $this->isInteractive()
            ? $this->decorate('bold', $label . ':')
            : $label . ':';

        $this->clearEphemeralLine();
        fwrite($this->stdout, sprintf("%s %s\n", $formattedLabel, $value));
    }

    public function progress(string $message, bool $singleLine): void
    {
        if ($this->sink !== null) {
            ($this->sink)(ConsoleOutputLevel::Progress, $message);

            return;
        }

        if ($singleLine && $this->isInteractive()) {
            $this->hasEphemeralLine = true;
            fwrite(
                $this->stdout,
                sprintf("\r\033[2K%s %s", $this->decorate('cyan', 'bold', '●'), $message),
            );

            return;
        }

        $this->info($message);
    }

    public function finishProgress(): void
    {
        if (!$this->hasEphemeralLine) {
            return;
        }

        fwrite($this->stdout, PHP_EOL);
        $this->hasEphemeralLine = false;
    }

    private function writeStdoutLine(ConsoleOutputLevel $level, string $interactivePrefix, string $plainPrefix, string $message): void
    {
        $this->writeLine($this->stdout, $level, $interactivePrefix, $plainPrefix, $message);
    }

    private function writeStderrLine(ConsoleOutputLevel $level, string $interactivePrefix, string $plainPrefix, string $message): void
    {
        $this->writeLine($this->stderr, $level, $interactivePrefix, $plainPrefix, $message);
    }

    /**
     * @param resource $stream
     */
    private function writeLine($stream, ConsoleOutputLevel $level, string $interactivePrefix, string $plainPrefix, string $message): void
    {
        if ($this->sink !== null) {
            ($this->sink)($level, $message);

            return;
        }

        $this->clearEphemeralLine();
        $prefix = $this->isInteractive() ? $interactivePrefix : $plainPrefix;
        fwrite($stream, sprintf("%s %s\n", $prefix, $message));
    }

    private function clearEphemeralLine(): void
    {
        if (!$this->hasEphemeralLine) {
            return;
        }

        fwrite($this->stdout, PHP_EOL);
        $this->hasEphemeralLine = false;
    }

    private function decorate(string ...$parts): string
    {
        $message = array_pop($parts);
        if (!is_string($message) || !$this->isInteractive()) {
            return is_string($message) ? $message : '';
        }

        $codes = [];
        foreach ($parts as $part) {
            $codes[] = match ($part) {
                'bold' => '1',
                'red' => '31',
                'green' => '32',
                'yellow' => '33',
                'blue' => '34',
                'cyan' => '36',
                default => null,
            };
        }

        $codes = array_values(array_filter($codes, static fn (?string $code): bool => $code !== null));
        if ($codes === []) {
            return $message;
        }

        return sprintf("\033[%sm%s\033[0m", implode(';', $codes), $message);
    }
}
