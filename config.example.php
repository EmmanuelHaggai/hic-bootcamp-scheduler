<?php
// Copy to config.php. Prefer environment variables for all secrets.
return [
    'admin_password_hash' => getenv('HIC_ADMIN_HASH') ?: '',
    // Your relay translates the documented JSON contract to WhatsApp or SMS.
    'sms_enabled' => false, // Enable direct Africa's Talking delivery using DairyOS settings.
    'relay_url' => getenv('HIC_RELAY_URL') ?: '',
    'relay_token' => getenv('HIC_RELAY_TOKEN') ?: '',
    'webhook_secret' => getenv('HIC_WEBHOOK_SECRET') ?: '',
    'organizers' => [], // E.164 phone numbers, e.g. +254...
    'recipients' => [], // Only opted-in recipients; E.164 phone numbers
];
