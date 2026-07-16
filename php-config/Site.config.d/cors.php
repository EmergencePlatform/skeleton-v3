<?php

// set to * or an array of hosts
if ($origins = getenv('CORS_ORIGINS')) {
    Site::$permittedOrigins = $origins == '*' ? '*' : explode(',', $origins);
}
