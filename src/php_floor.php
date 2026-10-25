<?php

// First autoload.files entry: refuse to boot on an unpatched PHP before anything else runs.
// Self-contained on purpose (no dependency on other framework files being loaded yet).
require_once __DIR__ . '/Support/PhpRuntimeFloor.php';
\StoneScriptPHP\Support\PhpRuntimeFloor::enforce();
