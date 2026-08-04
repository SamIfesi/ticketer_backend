<?php

// AUTH ROUTES
// Public routes
$router->post('/api/auth/callback/google', [GoogleAuthController::class, 'googleLogin']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login',    [AuthController::class, 'login']);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPasswordOtp']);
$router->post('/api/auth/verify-forgot-otp', [AuthController::class, 'verifyForgottenPasswordOtp']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);

// Protected routes — must be logged in
$router->post('/api/auth/logout',      [AuthController::class, 'logout'],      [AuthMiddleware::class]);
$router->get('/api/auth/me',          [AuthController::class, 'me'],          [AuthMiddleware::class]);
$router->post('/api/auth/verify-email', [AuthController::class, 'verifyEmail'], [AuthMiddleware::class]);
$router->post('/api/auth/resend-otp',  [AuthController::class, 'resendOTP'],   [AuthMiddleware::class]);
