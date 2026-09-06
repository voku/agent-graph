<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

enum TraversalDirection: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';
}
