<?php

class Response
{
  /**
   * Send a success response
   */
  public static function success(mixed $data = null, string $message = 'Success', int $code = 200): void
  {
    self::send([
      'status'  => 'success',
      'message' => $message,
      'data'    => $data,
    ], $code);
  }

  /**
   * Send an error response
   */
  public static function error(string $message = 'Something went wrong', int $code = 400): void
  {
    self::send([
      'status'  => 'error',
      'message' => $message,
    ], $code);
  }

  /**
   * 401 Unauthorized — not logged in
   */
  public static function unauthorized(string $message = 'Unauthorized'): void
  {
    self::error($message, 401);
  }

  /**
   * 403 Forbidden — logged in but not allowed
   */
  public static function forbidden(string $message = 'Forbidden'): void
  {
    self::error($message, 403);
  }

  /**
   * 404 Not Found
   */
  public static function notFound(string $message = 'Not found'): void
  {
    self::error($message, 404);
  }

  /**
   * 429 Too Many Requests — rate limit exceeded
   */
  public static function tooManyRequests(string $message = 'Too many requests. Please try again later.', ?int $retryAfterSeconds = null): void
  {
    if ($retryAfterSeconds !== null) {
      header('Retry-After: ' . $retryAfterSeconds);
    }
    self::error($message, 429);
  }

  /**
   * 422 Validation Error — with field-level errors
   */
  public static function validationError(array $errors): void
  {
    self::send([
      'status' => 'error',
      'message' => 'Validation failed',
      'errors' => $errors,
    ], 422);
  }

  /**
   * Core method — sets headers and outputs JSON
   */
  private static function send(array $payload, int $code): void
  {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
  }
}
