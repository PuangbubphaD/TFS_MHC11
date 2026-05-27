<?php
require_once __DIR__ . '/../includes/db.php';
$totals = $pdo->query("
    SELECT
        (SELECT COALESCE(SUM(ar3.participants), 0) FROM activity_reports ar3 JOIN activities act3 ON ar3.activity_id = act3.id JOIN projects prj3 ON act3.project_id = prj3.id WHERE act3.deleted_at IS NULL AND prj3.deleted_at IS NULL) AS total_actual_participants
")->fetch();
var_dump($totals);
