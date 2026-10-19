<?php
declare(strict_types=1);
// Run after the existing audit/analytics foundation. Does not replace tables or role grants.
return \App\Services\EventTrackingSchema::install(\App\Core\Database::connection());
