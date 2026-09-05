<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Classifies a line the chaos run emits so both the plain log and the
 * fullscreen watch view can present it consistently.
 */
enum ChaosWatchLogLevel: string
{
    case Plan = 'plan';
    case Event = 'chaos';
    case Progress = 'exec';
    case Waiting = 'wait';
    case Done = 'done';
    case Warning = 'warn';
    case Failure = 'fail';
    case Info = 'info';

    public static function fromConsoleOutputLevel(ConsoleOutputLevel $level): self
    {
        return match ($level) {
            ConsoleOutputLevel::Step, ConsoleOutputLevel::Progress => self::Progress,
            ConsoleOutputLevel::Info, ConsoleOutputLevel::Detail => self::Info,
            ConsoleOutputLevel::Success => self::Done,
            ConsoleOutputLevel::Warning => self::Warning,
            ConsoleOutputLevel::Error => self::Failure,
        };
    }

    /**
     * Prefix the line-oriented chaos log uses. Only the levels that describe a
     * whole event are prefixed; progress and poll chatter stays unadorned.
     */
    public function plainPrefix(): string
    {
        return match ($this) {
            self::Event => '[chaos] ',
            self::Plan => '[plan ] ',
            self::Done => '[done ] ',
            default => '',
        };
    }

    /**
     * Levels that only make sense while watching: without a live view they
     * bury the events that actually changed the cluster.
     */
    public function isVerbose(): bool
    {
        return match ($this) {
            self::Waiting, self::Progress, self::Info => true,
            default => false,
        };
    }
}
