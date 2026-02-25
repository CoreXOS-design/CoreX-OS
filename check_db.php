<?php
$db = new SQLite3('docuperfect.db');
$r = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
while($row = $r->fetchArray()) echo $row[0] . PHP_EOL;
echo '---' . PHP_EOL;
echo 'Templates: ' . $db->querySingle('SELECT count(*) FROM docuperfect_templates') . PHP_EOL;
echo 'Documents: ' . $db->querySingle('SELECT count(*) FROM docuperfect_documents') . PHP_EOL;
