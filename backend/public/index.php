<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Locally\Http\Kernel;

$kernel = new Kernel();
$kernel->handle()->send();
