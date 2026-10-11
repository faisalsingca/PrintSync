<?php

return [
    // Set OTP_ENABLED=false in .env to go back to normal password-only login.
    'enabled' => (bool) env('OTP_ENABLED', true),

    'expires_minutes' => 10,

    'max_attempts' => 5,

    'resend_seconds' => 30,
];