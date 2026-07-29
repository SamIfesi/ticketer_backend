<?php

class Database
{
  private static ?PDO $instance = null;                               // Holds the single PDO instance

  private function __construct() {}

  public static function connect(): PDO
  {
    if (self::$instance === null) {
      $host   = Environment::get('DATABASE_HOST', 'localhost');
      $name   = Environment::get('DATABASE_NAME');
      $user   = Environment::get('DATABASE_USER');
      $pass   = Environment::get('DATABASE_PASS', '');
      $port   = Environment::get('DATABASE_PORT', 3306);

      $dsn = "mysql:host={$host};dbname={$name};port={$port};charset=utf8mb4";

      try {
        self::$instance = new PDO($dsn, $user, $pass, [
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,     // Throw exceptions on errors instead of silent failures
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,           // Return results as associative arrays by default
          PDO::ATTR_EMULATE_PREPARES   => false,                      // Disable emulated prepares for true prepared statements
        ]);
        self::$instance->exec("SET time_zone = '+00:00'");            // Ensure all timestamps are stored in UTC
      } catch (PDOException $e) {
        http_response_code(500);                                      // In production you would log this, not expose the message
        echo json_encode(['error' => $e->getMessage()]);
        exit;
      }
    }

    return self::$instance;
  }
}
