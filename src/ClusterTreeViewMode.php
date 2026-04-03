<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

enum ClusterTreeViewMode
{
    case AllNodes;
    case PrimariesOnly;
}
