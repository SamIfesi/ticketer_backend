<?php

class Constants
{
  // User roles — must match ENUM in users table
  const ROLE_ATTENDEE   = 'attendee';
  const ROLE_ORGANIZER  = 'organizer';
  const ROLE_ADMIN      = 'admin';
  const ROLE_DEV        = 'dev';

  // Roles that admins can see and assign (dev is intentionally excluded)
  const PUBLIC_ROLES = [
    self::ROLE_ATTENDEE,
    self::ROLE_ORGANIZER,
    self::ROLE_ADMIN,
  ];

  // Event statuses — must match ENUM in events table
  const EVENT_DRAFT      = 'draft';
  const EVENT_PUBLISHED  = 'published';
  const EVENT_CANCELLED  = 'cancelled';
  const EVENT_COMPLETED  = 'completed';
  const EVENT_DELETED    = 'deleted';

  // Payment statuses — must match ENUM in bookings table
  const PAYMENT_PENDING  = 'pending';
  const PAYMENT_PAID     = 'paid';
  const PAYMENT_FAILED   = 'failed';
  const PAYMENT_REFUNDED = 'refunded';

  // PAYOUT CONSTANTS - This is the fraud protection buffer.
  // 48 hours = attendees have 2 days to report a scam event.
  const PAYOUT_HOLD_HOURS = 48;

  // Payout statuses — must match ENUM in event_payouts table
  const PAYOUT_PENDING   = 'pending';
  const PAYOUT_PROCESSING = 'processing';
  const PAYOUT_PAID      = 'paid';
  const PAYOUT_FAILED    = 'failed';
  const PAYOUT_FROZEN    = 'frozen';
  const PAYOUT_CANCELLED = 'cancelled';

  // ── STRIKE SYSTEM ────────────────────────────────────────────
  const ORGANIZER_STRIKE_THRESHOLD = 3;

  // Storage paths
  const STORAGE_TICKETS  = __DIR__ . '/../storage/tickets/';
  const STORAGE_QRCODES  = __DIR__ . '/../storage/qrcodes/';
  const STORAGE_BANNERS  = __DIR__ . '/../storage/banners/';

  // checkin mode - for multi day check in
  const CHECKIN_MODE_SINGLE    = 'single';
  const CHECKIN_MODE_MULTI_DAY = 'multi_day';
}
