<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

enum NodeLatencyState: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Timeout = 'timeout';
    case Error = 'error';
}
