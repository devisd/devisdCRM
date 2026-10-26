<?php

declare(strict_types=1);

namespace DevisCrm\Api\Clients;

enum ClientDeletion
{
    case Deleted;
    case NotFound;
    case HasOrders;
}
