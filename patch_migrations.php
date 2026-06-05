<?php
$dir = __DIR__ . '/src/Migrations';
foreach (glob("$dir/*.php") as $file) {
    $content = file_get_contents($file);
    if (strpos($content, 'isTransactional') === false) {
        $method = "public function isTransactional(): bool\n    {\n        return false;\n    }\n\n    public function up(";
        $content = str_replace('public function up(', $method, $content);
        file_put_contents($file, $content);
    }
}
echo "Migrations patched.\n";
