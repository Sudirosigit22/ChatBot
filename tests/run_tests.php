<?php
declare(strict_types=1);

/*
 * Menjalankan seluruh test suite proyek secara berurutan dalam proses PHP
 * terpisah (agar state global antar file test -- mis. $GLOBALS['AGENT_RUN']
 * -- tidak saling bocor), lalu merangkum hasilnya.
 *
 * Jalankan: php tests/run_tests.php
 */

$testFiles = [
    'AgentLayerTest.php',
    'CalculatorTest.php',
    'IntentClassifierTest.php',
];

$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$allPassed = true;

foreach ($testFiles as $file) {
    $path = __DIR__ . '/' . $file;
    echo "==================================================\n";
    echo "Menjalankan {$file}\n";
    echo "==================================================\n";
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    echo implode("\n", $output) . "\n\n";
    if ($exitCode !== 0) {
        $allPassed = false;
        echo ">>> {$file} GAGAL (exit code {$exitCode})\n\n";
    }
}

echo "==================================================\n";
echo $allPassed ? "SEMUA TEST SUITE LULUS.\n" : "ADA TEST SUITE YANG GAGAL. Periksa log di atas.\n";
echo "==================================================\n";

exit($allPassed ? 0 : 1);
