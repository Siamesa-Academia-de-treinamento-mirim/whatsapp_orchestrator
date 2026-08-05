<?php

declare(strict_types=1);

// Compatibility entrypoint retained for older deployment scripts.
// The former suite targeted removed AI/report/n8n modules and is intentionally
// replaced by the current product contract checks.
require __DIR__ . '/run_product_static.php';
