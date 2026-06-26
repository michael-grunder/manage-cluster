<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;
use LogicException;

enum KillMethod: string
{
    case Shutdown = 'shutdown';
    case NoSave = 'nosave';
    case SigTerm = 'sigterm';
    case SigQuit = 'sigquit';
    case SigSegv = 'sigsegv';
    case SigKill = 'sigkill';
    case SigAbrt = 'sigabrt';
    case SigBus = 'sigbus';

    public static function parse(string $value): self
    {
        $method = self::tryFrom(strtolower($value));
        if ($method === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported kill method: %s. Supported methods: %s.',
                $value,
                implode(', ', self::supportedValues()),
            ));
        }

        return $method;
    }

    /**
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }

    public function isSignal(): bool
    {
        return match ($this) {
            self::Shutdown, self::NoSave => false,
            self::SigTerm, self::SigQuit, self::SigSegv, self::SigKill, self::SigAbrt, self::SigBus => true,
        };
    }

    public function shutdownNoSave(): bool
    {
        return match ($this) {
            self::Shutdown => false,
            self::NoSave => true,
            default => throw new LogicException(sprintf('%s is not a Redis shutdown method.', $this->value)),
        };
    }

    public function signalNumber(): int
    {
        return match ($this) {
            self::SigTerm => 15,
            self::SigQuit => 3,
            self::SigSegv => 11,
            self::SigKill => 9,
            self::SigAbrt => 6,
            self::SigBus => 7,
            default => throw new LogicException(sprintf('%s is not a signal method.', $this->value)),
        };
    }

    public function signalName(): string
    {
        return match ($this) {
            self::SigTerm => 'TERM',
            self::SigQuit => 'QUIT',
            self::SigSegv => 'SEGV',
            self::SigKill => 'KILL',
            self::SigAbrt => 'ABRT',
            self::SigBus => 'BUS',
            default => throw new LogicException(sprintf('%s is not a signal method.', $this->value)),
        };
    }

    public function commandLabel(): string
    {
        return match ($this) {
            self::Shutdown => 'SHUTDOWN',
            self::NoSave => 'SHUTDOWN NOSAVE',
            default => sprintf('SIG%s', $this->signalName()),
        };
    }

    public function completionVerb(): string
    {
        return $this->isSignal() ? 'Killed' : 'Stopped';
    }
}
