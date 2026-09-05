<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Severity of a {@see ConsoleOutput} write, so a redirected sink can classify
 * messages it receives instead of parsing the rendered prefixes back out.
 */
enum ConsoleOutputLevel
{
    case Step;
    case Info;
    case Success;
    case Warning;
    case Error;
    case Detail;
    case Progress;
}
