<?php
header('Content-Type: application/json');

$commands = [
    'netstat' => 'netstat -tuln 2>&1',
    'ps' => 'ps aux | grep python 2>&1',
    'ss' => 'ss -tuln 2>&1'
];

$results = [];
foreach ($commands as $name => $cmd) {
    $output = shell_exec($cmd);
    $results[$name] = [
        'command' => $cmd,
        'output' => $output
    ];
}

echo json_encode($results);
