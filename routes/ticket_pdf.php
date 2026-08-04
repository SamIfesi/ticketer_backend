<?php

// TICKET PDF + PNG ROUTES
//
// Downloads are always single-ticket. There is no booking-level
// bulk/ZIP download route — the frontend downloads each ticket
// under a booking one at a time via TicketsService.downloadAllTickets().
//
// NOTE: /status and /regenerate operate on a whole booking (they
// check/rebuild every ticket under it), but the actual download
// routes are keyed by ticket id, not booking id.

// ── Attendee / Organizer — booking-level status + regenerate ──

// Check if every ticket under a booking has been generated (PDF + PNG status)
$router->get(
  '/api/bookings/:id/ticket/status',
  [TicketPDFController::class, 'status'],
  [AuthMiddleware::class]
);

// ── Attendee / Organizer — per-ticket downloads ────────────────

// Download ONE ticket as PNG image
$router->get(
  '/api/tickets/:id/download/png',
  [TicketPDFController::class, 'downloadSinglePng'],
  [AuthMiddleware::class]
);

// Download ONE ticket as PDF (generates on first request, cached after)
$router->get(
  '/api/tickets/:id/download',
  [TicketPDFController::class, 'downloadSingle'],
  [AuthMiddleware::class]
);

// ── Admin / Dev ───────────────────────────────────────────────

// Force regenerate every ticket (PDF + PNG) under a booking
$router->post(
  '/api/bookings/:id/ticket/regenerate',
  [TicketPDFController::class, 'regenerate'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

// Admin download any ticket's PNG (register before the PDF route —
// same 5-segment shape, so /png must be matched first)
$router->get(
  '/api/admin/tickets/:id/download/png',
  [TicketPDFController::class, 'adminDownloadSinglePng'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

// Admin download any ticket's PDF
$router->get(
  '/api/admin/tickets/:id/download',
  [TicketPDFController::class, 'adminDownloadSingle'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);
