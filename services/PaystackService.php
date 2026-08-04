<?php
class PaystackService
{
  private string $secretKey;
  private string $baseUrl = 'https://api.paystack.co';

  public function __construct()
  {
    $this->secretKey = Environment::get('PAYSTACK_SECRET_KEY');
  }

  // Initialize a transaction (unchanged)
  public function initializeTransaction(string $email, float $amount, string $reference, array $metadata = []): array
  {
    $body = json_encode([
      'email'     => $email,
      'amount'    => (int) ($amount * 100),
      'reference' => $reference,
      'metadata'  => $metadata,
      'currency'  => 'NGN',
    ]);

    $response = $this->makeRequest('POST', '/transaction/initialize', $body);

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to initialize payment.');
    }
    return $response['data'];
  }

  // EXISTING — verify a transaction (unchanged)
  public function verifyTransaction(string $reference): array
  {
    $response = $this->makeRequest('GET', "/transaction/verify/{$reference}");

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to verify payment.');
    }

    return $response['data'];
  }

  // NEW — Resolve account number
  // Verifies a bank account and returns the account holder name.
  // Call this before saving organizer bank details.
  //
  // Returns: ['account_name' => 'JOHN DOE', 'account_number' => '0123456789']
  // Throws on invalid account.
  public function resolveAccountNumber(string $accountNumber, string $bankCode): array
  {
    $response = $this->makeRequest(
      'GET',
      "/bank/resolve?account_number={$accountNumber}&bank_code={$bankCode}"
    );

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Could not resolve account number. Please check the details.');
    }

    return $response['data'];
  }

  // NEW — Get list of supported banks
  // Frontend uses this to populate the bank dropdown.
  // Returns array of ['name' => 'GTBank', 'code' => '058', ...]
  public function getBanks(): array
  {
    $response = $this->makeRequest('GET', '/bank?currency=NGN&per_page=100');

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Could not fetch bank list.');
    }

    return $response['data'];
  }

  // NEW — Create a Paystack subaccount for an organizer
  // Called automatically when organizer saves their bank details.
  //
  // $businessName     — organizer's name or business name
  // $settlementBank   — bank code e.g. "058"
  // $accountNumber    — 10-digit NUBAN
  // $percentageCharge — platform's cut e.g. 10.0 = 10%
  //
  // Returns full subaccount data including 'subaccount_code'
  // which you store in organizer_payment_details.
  public function createSubaccount(
    string $businessName,
    string $settlementBank,
    string $accountNumber,
    float  $percentageCharge
  ): array {
    $body = json_encode([
      'business_name'    => $businessName,
      'settlement_bank'  => $settlementBank,
      'account_number'   => $accountNumber,
      'percentage_charge' => $percentageCharge,
      'description'      => "Organizer subaccount for {$businessName}",
    ]);

    $response = $this->makeRequest('POST', '/subaccount', $body);

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to create Paystack subaccount.');
    }

    return $response['data'];
  }

  // NEW — Update an existing Paystack subaccount
  // Called when organizer updates their bank details.
  // $subaccountCode — stored in organizer_payment_details
  public function updateSubaccount(
    string $subaccountCode,
    string $businessName,
    string $settlementBank,
    string $accountNumber,
    float  $percentageCharge
  ): array {
    $body = json_encode([
      'business_name'    => $businessName,
      'settlement_bank'  => $settlementBank,
      'account_number'   => $accountNumber,
      'percentage_charge' => $percentageCharge,
    ]);

    $response = $this->makeRequest('PUT', "/subaccount/{$subaccountCode}", $body);

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to update Paystack subaccount.');
    }

    return $response['data'];
  }

  // NEW — Create a transfer recipient
  // Paystack requires a recipient object before you can transfer.
  // This wraps the bank details into a reusable recipient_code.
  //
  // Returns recipient_code — store this in organizer_payment_details
  // for use in initiateTransfer().
  public function createTransferRecipient(
    string $accountName,
    string $accountNumber,
    string $bankCode
  ): array {
    $body = json_encode([
      'type'           => 'nuban',
      'name'           => $accountName,
      'account_number' => $accountNumber,
      'bank_code'      => $bankCode,
      'currency'       => 'NGN',
    ]);

    $response = $this->makeRequest('POST', '/transferrecipient', $body);

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to create transfer recipient.');
    }

    return $response['data'];
  }

  // NEW — Initiate a Transfer (payout to organizer)
  // $amount         — amount in naira (converted to kobo internally)
  // $recipientCode  — from createTransferRecipient()
  // $reference      — your unique reference for this payout
  // $reason         — shown on organizer's bank statement
  //
  // Returns transfer data including 'transfer_code'
  public function initiateTransfer(
    float  $amount,
    string $recipientCode,
    string $reference,
    string $reason = 'Event payout'
  ): array {
    $body = json_encode([
      'source'    => 'balance',
      'amount'    => (int) ($amount * 100),
      'recipient' => $recipientCode,
      'reference' => $reference,
      'reason'    => $reason,
    ]);

    $response = $this->makeRequest('POST', '/transfer', $body);

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to initiate transfer.');
    }

    return $response['data'];
  }

  // NEW — Verify a Transfer status
  // Call this to confirm a transfer actually completed.
  // $transferCode — returned by initiateTransfer()
  public function verifyTransfer(string $transferCode): array
  {
    $response = $this->makeRequest('GET', "/transfer/{$transferCode}");

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to verify transfer.');
    }

    return $response['data'];
  }

  // NEW — Refund a transaction
  // Called when an event is cancelled and attendees need refunds.
  // $transactionRef — the original Paystack payment reference
  // $amount         — amount to refund in naira (NULL = full refund)
  public function refundTransaction(string $transactionRef, ?float $amount = null): array
  {
    $payload = ['transaction' => $transactionRef];

    if ($amount !== null) {
      $payload['amount'] = (int) ($amount * 100);
    }

    $response = $this->makeRequest('POST', '/refund', json_encode($payload));

    if (!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to initiate refund.');
    }

    return $response['data'];
  }

  // PRIVATE — core HTTP request handler (unchanged from original)
  private function makeRequest(string $method, string $endpoint, ?string $body = null): array
  {
    $curl = curl_init();

    $options = [
      CURLOPT_URL            => $this->baseUrl . $endpoint,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false, // REMOVE ME DURING DEVELOPMENT
      CURLOPT_SSL_VERIFYHOST => false, // REMOVE ME TOO
      CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $this->secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
      ],
      CURLOPT_TIMEOUT        => 30,
    ];

    if ($method === 'POST') {
      $options[CURLOPT_POST]       = true;
      $options[CURLOPT_POSTFIELDS] = $body;
    } elseif ($method === 'PUT') {
      $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
      $options[CURLOPT_POSTFIELDS]    = $body;
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $error    = curl_error($curl);

    curl_close($curl);

    if ($error) {
      throw new Exception('Network error contacting Paystack: ' . $error);
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new Exception('Invalid response from Paystack.');
    }

    return $decoded;
  }
}
