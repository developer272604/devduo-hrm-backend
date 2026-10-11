<?php

namespace App\Domain\Events;

/** Laravel event fired after a DomainEvent row is stored. Subscribe in a service provider or listener. */
final class DomainEventRecorded
{
    public function __construct(public readonly DomainEvent $event) {}
}
