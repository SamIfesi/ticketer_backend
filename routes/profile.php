<?php

// PROFILE ROUTES
// All protected: must be logged in

// View and update profile
$router->get('/api/profile',                      [ProfileController::class, 'show'],               [AuthMiddleware::class]);
$router->put('/api/profile',                      [ProfileController::class, 'update'],             [AuthMiddleware::class]);

// Password change
$router->post('/api/profile/change-password',      [ProfileController::class, 'changePassword'],     [AuthMiddleware::class]);

// Email change (2-step)
$router->post('/api/profile/change-email',         [ProfileController::class, 'requestEmailChange'], [AuthMiddleware::class]);
$router->post('/api/profile/confirm-email-change', [ProfileController::class, 'confirmEmailChange'], [AuthMiddleware::class]);

// History and activity
$router->get('/api/profile/bookings',             [ProfileController::class, 'bookings'],           [AuthMiddleware::class]);
$router->get('/api/profile/tickets',              [ProfileController::class, 'tickets'],            [AuthMiddleware::class]);
$router->get('/api/profile/activity',             [ProfileController::class, 'activity'],           [AuthMiddleware::class]);
