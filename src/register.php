<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Messenger\MessengerAdapter;

AdapterRegistry::register('messenger', MessengerAdapter::class);
