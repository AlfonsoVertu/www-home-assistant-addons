<?php
$file = '/var/www/html/wp-content/plugins/wp-gpt-automation-pro/includes/class-wp-gpt-api.php';
if (!file_exists($file)) {
    echo "File not found!";
    exit;
}
$content = file_get_contents($file);
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'WP_GPT_User_Helper') !== false) {
        echo ($i + 1) . ": " . htmlspecialchars($line) . "<br>\n";
    }
}
