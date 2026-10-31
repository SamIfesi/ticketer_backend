<?php
// NOTE: /summary and /mine MUST come before /:bookingId

$router->get(
  '/api/transactions/mine',
  [TransactionController::class, 'mine'],
  [AuthMiddleware::class]
);

$router->get(
  '/api/organizer/transactions',
  [TransactionController::class, 'organizer'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->get(
  '/api/admin/transactions/summary',
  [TransactionController::class, 'summary'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->get(
  '/api/admin/transactions',
  [TransactionController::class, 'admin'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->get(
  '/api/admin/transactions/:bookingId',
  [TransactionController::class, 'byBooking'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);