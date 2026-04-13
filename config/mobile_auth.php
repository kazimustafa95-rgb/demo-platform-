<?php

return [
    'verification_code_length' => env('MOBILE_AUTH_VERIFICATION_CODE_LENGTH', 6),
    'verification_code_expire_minutes' => env('MOBILE_AUTH_VERIFICATION_CODE_EXPIRE_MINUTES', 10),
    'verification_code_resend_cooldown_seconds' => env('MOBILE_AUTH_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),
];
