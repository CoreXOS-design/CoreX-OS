<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::table('deposit_trust_interest')->get();
$sql = "-- Trust Interest Register export\n-- " . count($rows) . " rows\n\n";

foreach ($rows as $row) {
    $cols = array_keys((array)$row);
    $vals = array_map(function($v) {
        if (is_null($v)) return 'NULL';
        if (is_numeric($v)) return $v;
        return "'" . addslashes($v) . "'";
    }, array_values((array)$row));
    $sql .= "INSERT INTO deposit_trust_interest (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
}

file_put_contents('trust_interest_export.sql', $sql);
echo 'Exported ' . count($rows) . " rows\n";
echo 'File size: ' . filesize('trust_interest_export.sql') . " bytes\n";
