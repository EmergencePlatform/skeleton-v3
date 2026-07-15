<?php

// skeleton-v3 container runtime defaults. SITE_DEBUG is set by the container
// entrypoint/front controller before Site::initialize, so only re-assert
// derived flags here (config.d files load after the layer defaults in
// Site.config.php / Site.config.d/*).
Site::$debug = filter_var(getenv('SITE_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
Site::$production = !Site::$debug;

// gen-1 VFS runtime parent-pulling does not exist in the container runtime;
// composition happens at build time via hologit projection
Site::$autoPull = false;
