<?php

/**
 * restore.php
 *
 * Restores a .dump/.sql file into a MySQL database using mysqli
 * (mysqlnd supports caching_sha2_password natively, unlike XAMPP's
 * bundled MariaDB command-line client).
 *
 * Usage (from CLI, inside C:\xampp\htdocs\ticketer):
 *   php database\restore.php --host=sakura.proxy.rlwy.net --port=12661 --user=root --db=railway --file=database\backup.dump
 *
 * You will be prompted for the password interactively so it never
 * appears in your shell history or process list.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

function arg(string $name, $default = null)
{
  foreach ($GLOBALS['argv'] as $a) {
    if (str_starts_with($a, "--{$name}=")) {
      return substr($a, strlen($name) + 3);
    }
  }
  return $default;
}

$host = arg('host', '127.0.0.1');
$port = (int) arg('port', 3306);
$user = arg('user', 'root');
$db   = arg('db', 'railway');
$file = arg('file', __DIR__ . '/backup.dump');

// Prompt for password without echoing it to the terminal.
fwrite(STDOUT, "Password for {$user}@{$host}:{$port}: ");
if (stripos(PHP_OS, 'WIN') === 0) {
  // Windows PowerShell/cmd has no stty; fall back to a visible prompt.
  $password = trim(fgets(STDIN));
} else {
  system('stty -echo');
  $password = trim(fgets(STDIN));
  system('stty echo');
  fwrite(STDOUT, "\n");
}

if (!is_file($file)) {
  fwrite(STDERR, "\nDump file not found: {$file}\n");
  exit(1);
}

fwrite(STDOUT, "\nConnecting to {$host}:{$port}/{$db} ...\n");

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();

if (!$conn->real_connect($host, $user, $password, $db, $port)) {
  fwrite(STDERR, "Connection failed: " . mysqli_connect_error() . "\n");
  exit(1);
}

fwrite(STDOUT, "Connected. Reading dump file...\n");

$sql = file_get_contents($file);
if ($sql === false) {
  fwrite(STDERR, "Could not read {$file}\n");
  exit(1);
}

fwrite(STDOUT, "Executing " . number_format(strlen($sql)) . " bytes of SQL. This may take a moment...\n");

// multi_query lets the MySQL server parse statement boundaries itself,
// so semicolons inside quoted string data (e.g. addresses, descriptions)
// are handled correctly.
if (!$conn->multi_query($sql)) {
  fwrite(STDERR, "Query failed: " . $conn->error . "\n");
  exit(1);
}

$statementCount = 0;
do {
  if ($result = $conn->store_result()) {
    $result->free();
  }
  $statementCount++;
  if ($conn->more_results()) {
    $next = $conn->next_result();
    if (!$next) {
      fwrite(STDERR, "\nError on statement #{$statementCount}: " . $conn->error . "\n");
      exit(1);
    }
  } else {
    break;
  }
} while (true);

fwrite(STDOUT, "Done. Executed {$statementCount} statement(s).\n");

// Quick sanity check
$tables = [];
$res = $conn->query('SHOW TABLES');
while ($row = $res->fetch_row()) {
  $tables[] = $row[0];
}
fwrite(STDOUT, "Tables now in '{$db}': " . implode(', ', $tables) . "\n");

foreach (['users', 'bookings', 'tickets'] as $t) {
  if (in_array($t, $tables, true)) {
    $c = $conn->query("SELECT COUNT(*) AS c FROM `{$t}`")->fetch_assoc()['c'];
    fwrite(STDOUT, "  {$t}: {$c} rows\n");
  }
}

$conn->close();
