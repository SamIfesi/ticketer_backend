<?php
class WebhookController
{
  public function __construct(private Request $request) {}

  public function paystack(): void
  {
    $raw    = file_get_contents('php://input');
    $sig    = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $secret = Environment::get('LIVE_PAYSTACK_SECRET_KEY');

    if (!hash_equals(hash_hmac('sha512', $raw, $secret), $sig)) {
      http_response_code(401);
      exit;
    }

    $event = json_decode($raw, true);

    if (($event['event'] ?? '') === 'charge.success') {
      $ref       = $event['data']['reference'] ?? '';
      $bookingId = (int) ($event['data']['metadata']['booking_id'] ?? 0);
      try {
        BookingFulfillment::run($ref, $bookingId ?: null);
      } catch (\Throwable $e) {
        error_log('Webhook fulfil error: ' . $e->getMessage());
        http_response_code(500); // Paystack will retry
        exit;
      }
    }

    http_response_code(200);
    echo 'ok';
  }
}
