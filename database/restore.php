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
$file = arg('file', __DIR__ . '/railway.dump');

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

// mysqldump wraps trigger/procedure/function bodies in
// "DELIMITER ;;  ...  DELIMITER ;" blocks, since those bodies contain
// semicolons of their own. DELIMITER is a mysql-CLI-only directive —
// mysqli doesn't understand it — so pull those blocks out, strip the
// DELIMITER lines, and run each definition as its own single query()
// call (its internal semicolons are fine within one statement).
$delimiterBlocks = [];
$sql = preg_replace_callback(
  '/DELIMITER\s+;;(.*?)DELIMITER\s+;/s',
  function ($m) use (&$delimiterBlocks) {
    foreach (explode(';;', $m[1]) as $stmt) {
      $stmt = trim($stmt);
      if ($stmt !== '') {
        $delimiterBlocks[] = $stmt;
      }
    }
    return ''; // remove the block from the main SQL body
  },
  $sql
);

fwrite(STDOUT, "Executing " . number_format(strlen($sql)) . " bytes of SQL. This may take a moment...\n");

// multi_query lets the MySQL server parse statement boundaries itself,
// so semicolons inside quoted string data (e.g. addresses, descriptions)
// are handled correctly.
$statementCount = 0;
if (trim($sql) !== '') {
  if (!$conn->multi_query($sql)) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
  }

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
}

fwrite(STDOUT, "Done. Executed {$statementCount} statement(s).\n");

// Now run any trigger/procedure/function definitions pulled out above,
// each as its own single query (internal semicolons are safe here
// because the whole body is sent as ONE statement, not split).
if (!empty($delimiterBlocks)) {
  fwrite(STDOUT, "Creating " . count($delimiterBlocks) . " trigger/procedure/function definition(s)...\n");
  foreach ($delimiterBlocks as $i => $stmt) {
    if (!$conn->query($stmt)) {
      fwrite(STDERR, "Error creating definition #" . ($i + 1) . ": " . $conn->error . "\n");
      fwrite(STDERR, "Statement was:\n{$stmt}\n\n");
      exit(1);
    }
  }
  fwrite(STDOUT, "All trigger/procedure/function definitions created.\n");
}


// Quick sanity check
$tables = [];
$res = $conn->query('SHOW TABLES');
while ($row = $res->fetch_row()) {
  $tables[] = $row[0];
}
fwrite(STDOUT, "Tables now in '{$db}': " . implode(', ', $tables) . "\n");

foreach ([ 'activity_logs', 'bookings', 'categories', 'dev_logs', 'email_verifications', 'event_payouts', 'events', 'jobs', 'notifications', 'organizer_applications', 'organizer_payment_details', 'ticket_checkins', 'ticket_types', 'tickets', 'transaction_logs', 'users' ] as $t) {
  if (in_array($t, $tables, true)) {
    $c = $conn->query("SELECT COUNT(*) AS c FROM `{$t}`")->fetch_assoc()['c'];
    fwrite(STDOUT, "  {$t}: {$c} rows\n");
  }
}

$conn->close();
