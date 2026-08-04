<?php

/**
 * TicketPDFController
 *
 * Handles PDF and PNG ticket generation and download — one file
 * per ticket, always. There is no ZIP path anymore: multi-ticket
 * bookings download one ticket at a time (sequentially, from the
 * frontend), which sidesteps the browser content-type/extension
 * mismatch that corrupted downloads when a ZIP was served with a
 * ".pdf" filename.
 *
 * Routes:
 *   GET  /api/bookings/:id/ticket/status        → status()             — check if all tickets under a booking are ready
 *   POST /api/bookings/:id/ticket/regenerate    → regenerate()         — force regenerate all tickets under a booking (admin/dev)
 *   GET  /api/tickets/:id/download               → downloadSingle()     — attendee/organizer downloads one ticket's PDF
 *   GET  /api/tickets/:id/download/png           → downloadSinglePng()  — attendee/organizer downloads one ticket's PNG
 *   GET  /api/admin/tickets/:id/download         → adminDownloadSingle()    — admin downloads any ticket's PDF
 *   GET  /api/admin/tickets/:id/download/png     → adminDownloadSinglePng() — admin downloads any ticket's PNG
 *
 * Access rules:
 *   - Attendee can only download their own tickets
 *   - Organizer can download tickets for their own events
 *   - Admin / Dev can download any ticket
 */
class TicketPDFController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/tickets/:id/download
  //
  // Streams ONE ticket's PDF directly. Generates on first
  // request (which also warms every other ticket under the same
  // booking, since PDFService::generateTickets() processes the
  // whole booking in one pass), cached on subsequent calls.
  // ============================================================
  public function downloadSingle(array $params): void
  {
    $ticketId = (int) $params['id'];
    $userId   = $this->request->user['id'];
    $role     = $this->request->user['role'];

    $ticket = $this->fetchTicketOwnership($ticketId);

    if (!$ticket) {
      Response::notFound('Ticket not found.');
    }

    $isOwner     = (int) $ticket['user_id']      === $userId;
    $isOrganizer = (int) $ticket['organizer_id'] === $userId;
    $isAdmin     = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_DEV], true);

    if (!$isOwner && !$isOrganizer && !$isAdmin) {
      Response::forbidden('You do not have access to this ticket.');
    }

    if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for confirmed paid bookings.', 400);
    }

    $this->serveSinglePdf($ticketId, (int) $ticket['booking_id']);
  }

  // ============================================================
  // GET /api/tickets/:id/download/png
  // ============================================================
  public function downloadSinglePng(array $params): void
  {
    $ticketId = (int) $params['id'];
    $userId   = $this->request->user['id'];
    $role     = $this->request->user['role'];

    $ticket = $this->fetchTicketOwnership($ticketId);

    if (!$ticket) {
      Response::notFound('Ticket not found.');
    }

    $isOwner     = (int) $ticket['user_id']      === $userId;
    $isOrganizer = (int) $ticket['organizer_id'] === $userId;
    $isAdmin     = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_DEV], true);

    if (!$isOwner && !$isOrganizer && !$isAdmin) {
      Response::forbidden('You do not have access to this ticket.');
    }

    if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for confirmed paid bookings.', 400);
    }

    $this->serveSinglePng($ticketId, (int) $ticket['booking_id']);
  }

  // ============================================================
  // GET /api/admin/tickets/:id/download
  //
  // Admin downloads any ticket's PDF directly. Same logic as
  // downloadSingle() but no ownership check.
  // ============================================================
  public function adminDownloadSingle(array $params): void
  {
    $ticketId = (int) $params['id'];

    $ticket = $this->fetchTicketOwnership($ticketId);

    if (!$ticket) {
      Response::notFound('Ticket not found.');
    }

    if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for paid bookings.', 400);
    }

    $this->serveSinglePdf($ticketId, (int) $ticket['booking_id']);
  }

  // ============================================================
  // GET /api/admin/tickets/:id/download/png
  // ============================================================
  public function adminDownloadSinglePng(array $params): void
  {
    $ticketId = (int) $params['id'];

    $ticket = $this->fetchTicketOwnership($ticketId);

    if (!$ticket) {
      Response::notFound('Ticket not found.');
    }

    if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for paid bookings.', 400);
    }

    $this->serveSinglePng($ticketId, (int) $ticket['booking_id']);
  }

  // ============================================================
  // POST /api/bookings/:id/ticket/regenerate
  //
  // Force-regenerates every ticket file (PDF + PNG) under a
  // booking. Useful after admin edits or when cached files are
  // stale. Admin / Dev only.
  // ============================================================
  public function regenerate(array $params): void
  {
    $bookingId = (int) $params['id'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('Can only regenerate tickets for paid bookings.', 400);
    }

    // Delete cached PDF and PNG versions for every ticket under this booking
    PDFService::deleteTicket($bookingId);

    try {
      $filePaths  = PDFService::generateTickets($bookingId);
      $ticketIds  = $this->fetchTicketIdsForBooking($bookingId);

      Response::success([
        'booking_id'  => $bookingId,
        'ticket_ids'  => $ticketIds,
        'file_count'  => count($filePaths),
      ], 'Tickets regenerated successfully.');
    } catch (Exception $e) {
      error_log("TicketPDFController::regenerate error for booking #{$bookingId}: " . $e->getMessage());
      Response::error('Failed to regenerate tickets. Please try again.', 500);
    }
  }

  // ============================================================
  // GET /api/bookings/:id/ticket/status
  //
  // Check whether ticket files (PDF + PNG) have been generated
  // for every ticket under a booking. Returns per-ticket status
  // so the frontend can show which individual tickets are ready
  // to download.
  //
  // Accessible to booking owner, event organizer, admin, dev.
  // ============================================================
  public function status(array $params): void
  {
    $bookingId = (int) $params['id'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    $isOwner     = (int) $booking['user_id']      === $userId;
    $isOrganizer = (int) $booking['organizer_id'] === $userId;
    $isAdmin     = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_DEV], true);

    if (!$isOwner && !$isOrganizer && !$isAdmin) {
      Response::forbidden('You do not have access to this booking.');
    }

    $ticketIds = $this->fetchTicketIdsForBooking($bookingId);
    $tickets   = [];
    $allReady  = true;

    foreach ($ticketIds as $ticketId) {
      $pdfReady = PDFService::singleTicketExists($ticketId);
      $pngReady = PDFService::singleTicketPngExists($ticketId);

      if (!$pdfReady || !$pngReady) {
        $allReady = false;
      }

      $tickets[] = [
        'ticket_id'    => $ticketId,
        'pdf_ready'    => $pdfReady,
        'png_ready'    => $pngReady,
      ];
    }

    Response::success([
      'booking_id'       => $bookingId,
      'ticket_generated' => $allReady,
      'tickets'          => $tickets,
    ]);
  }

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================

  private function serveSinglePdf(int $ticketId, int $bookingId): void
  {
    if (!PDFService::singleTicketExists($ticketId)) {
      try {
        // Generates every ticket under this booking (cheap no-op
        // for files that already exist on disk).
        PDFService::generateTickets($bookingId);
      } catch (Exception $e) {
        error_log("serveSinglePdf generation error for ticket #{$ticketId}: " . $e->getMessage());
        Response::error('Could not generate ticket. Please try again shortly.', 500);
        return;
      }
    }

    $filePath = PDFService::getSingleTicketPath($ticketId);

    if (!file_exists($filePath)) {
      Response::error('Ticket file not found. Please try regenerating it.', 404);
      return;
    }

    $ticketIdPadded = str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
    $filename       = "Ticketer_Ticket_#{$ticketIdPadded}.pdf";

    if (ob_get_level()) {
      ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    exit;
  }

  private function serveSinglePng(int $ticketId, int $bookingId): void
  {
    if (!PDFService::singleTicketPngExists($ticketId)) {
      try {
        PDFService::generateTickets($bookingId);
      } catch (Exception $e) {
        error_log("serveSinglePng generation error for ticket #{$ticketId}: " . $e->getMessage());
        Response::error('Could not generate ticket image. Please try again shortly.', 500);
        return;
      }
    }

    $filePath = PDFService::getSingleTicketPngPath($ticketId);

    if (!file_exists($filePath)) {
      Response::error('Ticket image not found. Please try regenerating it.', 404);
      return;
    }

    $ticketIdPadded = str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
    $filename       = "Ticketer_Ticket_#{$ticketIdPadded}.png";

    if (ob_get_level()) {
      ob_end_clean();
    }

    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    exit;
  }

  /**
   * Fetch ticket + booking + event ownership data keyed by ticket id.
   * Used by every ticket-level endpoint above.
   */
  private function fetchTicketOwnership(int $ticketId): array|false
  {
    $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.user_id,
                t.booking_id,
              t.ticket_number,
                b.payment_status,
              b.booking_number,
                e.organizer_id
            FROM tickets t
            JOIN bookings b ON b.id = t.booking_id
            JOIN events   e ON e.id = t.event_id
            WHERE t.id = ?
              AND t.deleted_at IS NULL
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$ticketId]);
    return $stmt->fetch();
  }

  /**
   * Fetch the minimal booking data needed for access checks.
   * Used by status() and regenerate(), which operate at the
   * booking level (a person checks/regenerates a whole booking's
   * worth of tickets at once, even though downloads are per-ticket).
   */
  private function fetchBookingOwnership(int $bookingId): array|false
  {
    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.user_id,
              b.booking_number,
                b.payment_status,
                e.organizer_id
            FROM bookings b
            JOIN events e ON e.id = b.event_id
            WHERE b.id = ?
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    return $stmt->fetch();
  }

  private function fetchTicketIdsForBooking(int $bookingId): array
  {
    $stmt = $this->db->prepare("
            SELECT id FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
            ORDER BY id ASC
        ");
    $stmt->execute([$bookingId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }
}
