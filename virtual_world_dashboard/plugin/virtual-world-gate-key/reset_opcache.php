<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "WP OPCache Reset Successful";
} else {
    echo "OPCache is not enabled or function disabled.";
}
