<?php

// DEV ROUTES — SECRET BACKDOOR
// All routes use DevMiddleware which returns 404 to non-dev users
// These routes are completely invisible to admins

$router->get('/api/dev/overview',              [DevController::class, 'overview'],       [DevMiddleware::class]);
$router->get('/api/dev/users',                 [DevController::class, 'users'],          [DevMiddleware::class]);

// Logs
$router->get('/api/dev/logs',                  [DevController::class, 'logs'],           [DevMiddleware::class]);
$router->get('/api/dev/logs/:id',              [DevController::class, 'showLog'],        [DevMiddleware::class]);
$router->delete('/api/dev/logs',                  [DevController::class, 'clearLogs'],      [DevMiddleware::class]);

// Force actions — for debugging during development
$router->post('/api/dev/users/:id/role',        [DevController::class, 'forceRole'],      [DevMiddleware::class]);
$router->get('/api/dev/bookings/failed',       [DevController::class, 'failedBookings'], [DevMiddleware::class]);
$router->post('/api/dev/bookings/:id/force-pay', [DevController::class, 'forcePay'],       [DevMiddleware::class]);
