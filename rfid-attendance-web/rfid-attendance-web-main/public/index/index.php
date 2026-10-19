<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Controllers\PortalController;

PortalController::handle((string) ($_GET['page'] ?? 'dashboard'));
