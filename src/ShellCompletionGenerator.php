<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class ShellCompletionGenerator
{
    /**
     * @var list<string>
     */
    private const array SUPPORTED_SHELLS = ['bash', 'zsh'];

    /**
     * @return list<string>
     */
    public static function supportedShells(): array
    {
        return self::SUPPORTED_SHELLS;
    }

    public function generate(string $shell, string $commandName = 'manage-cluster'): string
    {
        return match (strtolower($shell)) {
            'bash' => $this->generateBash($commandName),
            'zsh' => $this->generateZsh($commandName),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported completion shell: %s. Expected one of: %s.',
                $shell,
                implode(', ', self::SUPPORTED_SHELLS),
            )),
        };
    }

    private function generateBash(string $commandName): string
    {
        $functionName = $this->completionFunctionName($commandName);
        $commands = $this->bashWords([...CommandLineParser::actionNames(), 'help']);
        $globalOptions = $this->bashWords($this->optionNames(CommandLineParser::globalOptionSpecs()));
        $shells = $this->bashWords(self::SUPPORTED_SHELLS);
        $commandPattern = implode('|', [...CommandLineParser::actionNames(), 'help']);
        $optionCases = $this->bashOptionCases();

        return <<<BASH
# bash completion for {$commandName}
_{$functionName}()
{
    local cur prev command opts
    COMPREPLY=()
    cur="\${COMP_WORDS[COMP_CWORD]}"
    prev="\${COMP_WORDS[COMP_CWORD-1]}"
    command=""

    case "\$prev" in
        --binary|--redis-cli|--gen-script)
            COMPREPLY=( \$(compgen -f -- "\$cur") )
            return 0
            ;;
        --state-dir)
            COMPREPLY=( \$(compgen -d -- "\$cur") )
            return 0
            ;;
        --method)
            COMPREPLY=( \$(compgen -W "shutdown nosave sigterm sigquit sigsegv sigkill sigabrt sigbus" -- "\$cur") )
            return 0
            ;;
        --categories)
            COMPREPLY=( \$(compgen -W "replica-kill replica-restart replica-remove replica-add slot-migration" -- "\$cur") )
            return 0
            ;;
        --types)
            COMPREPLY=( \$(compgen -W "string set list hash zset" -- "\$cur") )
            return 0
            ;;
    esac

    for ((i = 1; i < COMP_CWORD; i++)); do
        case "\${COMP_WORDS[i]}" in
            --)
                break
                ;;
            {$commandPattern})
                command="\${COMP_WORDS[i]}"
                break
                ;;
        esac
    done

    if [[ "\$command" == "help" ]]; then
        if (( COMP_CWORD <= 2 )); then
            COMPREPLY=( \$(compgen -W "{$commands}" -- "\$cur") )
        fi
        return 0
    fi

    if [[ "\$command" == "completions" ]]; then
        if (( COMP_CWORD <= 2 )); then
            COMPREPLY=( \$(compgen -W "{$shells}" -- "\$cur") )
        fi
        return 0
    fi

    if [[ -z "\$command" || COMP_CWORD -eq 1 ]]; then
        COMPREPLY=( \$(compgen -W "{$commands} {$globalOptions}" -- "\$cur") )
        return 0
    fi

    if [[ "\$cur" == -* ]]; then
        case "\$command" in
{$optionCases}
            *)
                opts="{$globalOptions}"
                ;;
        esac
        COMPREPLY=( \$(compgen -W "\$opts" -- "\$cur") )
    fi

    return 0
}
complete -F _{$functionName} {$commandName}
BASH;
    }

    private function generateZsh(string $commandName): string
    {
        $functionName = $this->completionFunctionName($commandName);
        $commands = $this->zshDescribedArray(CommandLineParser::actionSummaries(), includeHelp: true);
        $shells = $this->zshArray(self::SUPPORTED_SHELLS);
        $optionCases = $this->zshOptionCases();
        $globalOptionSpecs = $this->zshArgumentSpecs(CommandLineParser::globalOptionSpecs());

        return <<<ZSH
#compdef {$commandName}

_{$functionName}()
{
    local context state line
    local -a commands shells
    commands=(
{$commands}
    )
    shells=(
{$shells}
    )

    _arguments -C \\
{$globalOptionSpecs}
        '1:command:->command' \\
        '*::arg:->args'

    case "\$state" in
        command)
            _describe -t commands '{$commandName} command' commands
            ;;
        args)
            case "\$words[2]" in
                help)
                    _describe -t commands '{$commandName} command' commands
                    ;;
                completions)
                    _describe -t shells 'shell' shells
                    ;;
{$optionCases}
            esac
            ;;
    esac
}

compdef _{$functionName} {$commandName}
ZSH;
    }

    /**
     * @return list<string>
     */
    private function optionNamesForCommand(string $command): array
    {
        return array_values(array_unique([
            ...$this->optionNames(CommandLineParser::globalOptionSpecs()),
            ...$this->optionNames(CommandLineParser::commandOptions($command)),
            '-h',
            '--help',
        ]));
    }

    /**
     * @param list<array{0:string,1:string}> $specs
     * @return list<string>
     */
    private function optionNames(array $specs): array
    {
        $options = [];
        foreach ($specs as [$rawOption]) {
            $rawOption = trim($rawOption);
            if ($rawOption === '--' || str_starts_with($rawOption, '-- ')) {
                continue;
            }

            $tokens = array_map('trim', explode(',', $rawOption));
            foreach ($tokens as $token) {
                $name = strtok($token, " \t");
                if (is_string($name) && str_starts_with($name, '-')) {
                    $options[] = $name;
                }
            }
        }

        return array_values(array_unique($options));
    }

    private function bashOptionCases(): string
    {
        $lines = [];
        foreach (CommandLineParser::actionNames() as $command) {
            $options = $this->bashWords($this->optionNamesForCommand($command));
            $lines[] = sprintf('            %s)', $command);
            $lines[] = sprintf('                opts="%s"', $options);
            $lines[] = '                ;;';
        }

        return implode(PHP_EOL, $lines);
    }

    private function zshOptionCases(): string
    {
        $lines = [];
        foreach (CommandLineParser::actionNames() as $command) {
            $options = $this->zshArray($this->optionNamesForCommand($command), indent: '                    ');
            $lines[] = sprintf('                %s)', $command);
            $lines[] = '                    local -a options';
            $lines[] = '                    options=(';
            $lines[] = $options;
            $lines[] = '                    )';
            $lines[] = "                    _describe -t options 'option' options";
            $lines[] = '                    ;;';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $words
     */
    private function bashWords(array $words): string
    {
        return implode(' ', $words);
    }

    /**
     * @param array<string, string> $descriptions
     */
    private function zshDescribedArray(array $descriptions, bool $includeHelp): string
    {
        $lines = [];
        foreach ($descriptions as $command => $description) {
            $lines[] = sprintf('        %s', $this->zshSingleQuoted(sprintf('%s:%s', $command, str_replace(':', '\:', $description))));
        }

        if ($includeHelp) {
            $lines[] = sprintf('        %s', $this->zshSingleQuoted('help:Print this message or the help of a given command'));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $values
     */
    private function zshArray(array $values, string $indent = '        '): string
    {
        $lines = [];
        foreach ($values as $value) {
            $lines[] = sprintf('%s%s', $indent, $this->zshSingleQuoted($value));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<array{0:string,1:string}> $specs
     */
    private function zshArgumentSpecs(array $specs): string
    {
        $lines = [];
        foreach ($specs as [$option, $description]) {
            foreach ($this->optionNames([[$option, $description]]) as $name) {
                $description = str_replace([']', ':'], ['\]', '\:'], $description);
                $valueCompletion = str_contains($option, 'PATH') ? ':path:_files' : '';
                $lines[] = sprintf("        '%s[%s]%s' \\", $name, $description, $valueCompletion);
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function zshSingleQuoted(string $value): string
    {
        return sprintf("'%s'", str_replace("'", "'\\''", $value));
    }

    private function completionFunctionName(string $commandName): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]+/', '_', $commandName);
        if (!is_string($name) || $name === '') {
            return 'manage_cluster';
        }

        return trim($name, '_') ?: 'manage_cluster';
    }
}
