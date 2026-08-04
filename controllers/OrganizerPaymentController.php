<?php

/**
 * OrganizerPaymentController
 *
 * Routes:
 *   GET    /api/organizer/payment-details       → show()
 *   POST   /api/organizer/payment-details       → store()
 *   PUT    /api/organizer/payment-details       → update()
 *   POST   /api/organizer/resolve-account       → resolveAccount()
 *   GET    /api/organizer/banks                 → getBanks()
 *   GET    /api/organizer/payouts               → myPayouts()
 */
class OrganizerPaymentController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/organizer/payment-details
  // Returns the organizer's saved bank details.
  // account_number is partially masked for security.
  public function show(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare("
            SELECT
                id, bank_name, bank_code,
                CONCAT('****', RIGHT(account_number, 4)) AS account_number_masked,
                account_name, paystack_subaccount_code,
                platform_fee_percentage, is_verified, is_flagged,
                flag_reason, cancellation_count, created_at, updated_at
            FROM organizer_payment_details
            WHERE user_id = ?
        ");
    $stmt->execute([$userId]);
    $details = $stmt->fetch();

    Response::success(['payment_details' => $details ?: null]);
  }

  // POST /api/organizer/resolve-account
  // Frontend calls this BEFORE saving to show the account name.
  // Body: { account_number, bank_code }
  // Returns: { account_name, account_number }
  public function resolveAccount(): void
  {
    $accountNumber = trim($this->request->input('account_number', ''));
    $bankCode      = trim($this->request->input('bank_code', ''));

    $errors = ValidationHelper::check(
      ['account_number' => $accountNumber, 'bank_code' => $bankCode],
      [
        'account_number' => 'required|min:10|max:10',
        'bank_code'      => 'required',
      ]
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    try {
      $paystack = new PaystackService();
      $resolved = $paystack->resolveAccountNumber($accountNumber, $bankCode);

      Response::success([
        'account_name'   => $resolved['account_name'],
        'account_number' => $resolved['account_number'],
      ]);
    } catch (Exception $e) {
      Response::error($e->getMessage(), 400);
    }
  }

  // GET /api/organizer/banks
  // Returns Paystack's list of supported banks.
  // Frontend uses this to populate the bank dropdown.
  public function getBanks(): void
  {
    try {
      $paystack = new PaystackService();
      $banks    = $paystack->getBanks();

      // Return only name and code — that's all frontend needs
      $simplified = array_map(fn($b) => [
        'name' => $b['name'],
        'code' => $b['code'],
      ], $banks);

      Response::success(['banks' => $simplified]);
    } catch (Exception $e) {
      Response::error('Could not fetch bank list. Please try again.', 500);
    }
  }

  // POST /api/organizer/payment-details
  // Save bank details for the first time.
  // Automatically:
  //   1. Resolves account name via Paystack
  //   2. Creates a Paystack transfer recipient
  //   3. Creates a Paystack subaccount
  //   4. Saves everything to organizer_payment_details
  // Body: { bank_name, bank_code, account_number, platform_fee_percentage }
  public function store(): void
  {
    $userId = $this->request->user['id'];

    // Check if details already exist
    $stmt = $this->db->prepare("SELECT id FROM organizer_payment_details WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
      Response::error('Bank details already saved. Use the update endpoint to change them.', 409);
    }

    $bankName    = trim($this->request->input('bank_name', ''));
    $bankCode    = trim($this->request->input('bank_code', ''));
    $accountNumber = trim($this->request->input('account_number', ''));
    $feePercent  = (float) $this->request->input('platform_fee_percentage', 0);

    $errors = ValidationHelper::check(
      [
        'bank_name'               => $bankName,
        'bank_code'               => $bankCode,
        'account_number'          => $accountNumber,
        'platform_fee_percentage' => (string) $feePercent,
      ],
      [
        'bank_name'               => 'required|min:2|max:100',
        'bank_code'               => 'required',
        'account_number'          => 'required|min:10|max:10',
        'platform_fee_percentage' => 'required|numeric',
      ]
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    if ($feePercent < 0 || $feePercent > 100) {
      Response::validationError(['platform_fee_percentage' => 'Fee percentage must be between 0 and 100.']);
    }

    // Fetch organizer name for Paystack business name
    $stmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    try {
      $paystack = new PaystackService();

      // Step 1 — Resolve and verify the account
      $resolved    = $paystack->resolveAccountNumber($accountNumber, $bankCode);
      $accountName = $resolved['account_name'];

      // Step 2 — Create transfer recipient (for payouts)
      $recipient     = $paystack->createTransferRecipient($accountName, $accountNumber, $bankCode);
      $recipientCode = $recipient['recipient_code'];

      // Step 3 — Create subaccount (for split payment records)
      $subaccount     = $paystack->createSubaccount(
        $user['name'],
        $bankCode,
        $accountNumber,
        $feePercent
      );
      $subaccountCode = $subaccount['subaccount_code'];
      $subaccountId   = $subaccount['id'] ?? null;

      // Step 4 — Save to database
      $this->db->prepare("
                INSERT INTO organizer_payment_details (
                    user_id, bank_name, bank_code, account_number, account_name,
                    paystack_subaccount_code, paystack_subaccount_id,
                    paystack_recipient_code,
                    platform_fee_percentage, is_verified
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ")->execute([
        $userId,
        $bankName,
        $bankCode,
        $accountNumber,
        $accountName,
        $subaccountCode,
        $subaccountId,
        $recipientCode,
        $feePercent,
      ]);

      // Log activity
      $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, 'bank_details_saved', 'Organizer added bank account details.', ?)
            ")->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null]);

      Response::success([
        'account_name'          => $accountName,
        'subaccount_code'       => $subaccountCode,
        'platform_fee_percentage' => $feePercent,
      ], 'Bank details saved and verified successfully.', 201);
    } catch (Exception $e) {
      Response::error($e->getMessage(), 400);
    }
  }

  // PUT /api/organizer/payment-details
  // Update existing bank details.
  // Re-verifies account and updates the Paystack subaccount.
  public function update(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare("SELECT * FROM organizer_payment_details WHERE user_id = ?");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();

    if (!$existing) {
      Response::notFound('No bank details found. Please add them first.');
    }

    $bankName      = trim($this->request->input('bank_name',      $existing['bank_name']));
    $bankCode      = trim($this->request->input('bank_code',      $existing['bank_code']));
    $accountNumber = trim($this->request->input('account_number', $existing['account_number']));
    $feePercent    = (float) $this->request->input('platform_fee_percentage', $existing['platform_fee_percentage']);

    if ($feePercent < 0 || $feePercent > 100) {
      Response::validationError(['platform_fee_percentage' => 'Fee percentage must be between 0 and 100.']);
    }

    $stmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    try {
      $paystack = new PaystackService();

      // Re-resolve account name
      $resolved    = $paystack->resolveAccountNumber($accountNumber, $bankCode);
      $accountName = $resolved['account_name'];

      // Update Paystack subaccount
      $paystack->updateSubaccount(
        $existing['paystack_subaccount_code'],
        $user['name'],
        $bankCode,
        $accountNumber,
        $feePercent
      );

      // Update database
      $this->db->prepare("
                UPDATE organizer_payment_details SET
                    bank_name                = ?,
                    bank_code                = ?,
                    account_number           = ?,
                    account_name             = ?,
                    platform_fee_percentage  = ?,
                    is_verified              = 1,
                    updated_at               = NOW()
                WHERE user_id = ?
            ")->execute([
        $bankName,
        $bankCode,
        $accountNumber,
        $accountName,
        $feePercent,
        $userId,
      ]);

      $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, 'bank_details_updated', 'Organizer updated bank account details.', ?)
            ")->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null]);

      Response::success([
        'account_name'            => $accountName,
        'platform_fee_percentage' => $feePercent,
      ], 'Bank details updated successfully.');
    } catch (Exception $e) {
      Response::error($e->getMessage(), 400);
    }
  }

  // GET /api/organizer/payouts
  // Organizer's own payout history
  public function myPayouts(): void
  {
    $userId = $this->request->user['id'];
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM event_payouts WHERE organizer_id = ?");
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $this->db->prepare("
            SELECT
                ep.id, ep.event_id, ep.gross_revenue,
                ep.platform_fee_percentage, ep.platform_fee_amount,
                ep.organizer_amount, ep.payout_status,
                ep.hold_until, ep.paid_at, ep.failed_at,
                ep.failure_reason, ep.freeze_reason,
                ep.created_at,
                e.title AS event_title,
                e.start_date AS event_start_date,
                e.end_date   AS event_end_date
            FROM event_payouts ep
            JOIN events e ON e.id = ep.event_id
            WHERE ep.organizer_id = ?
            ORDER BY ep.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute([$userId, $limit, $offset]);

    Response::success([
      'payouts'    => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }
}
