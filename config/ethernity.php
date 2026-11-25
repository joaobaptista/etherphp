<?php

$isProduction = env('APP_ENV', 'production') === 'production';

$extraAdmins = array_values(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('ADMIN_EMAILS', '')),
)));

$defaultRoots = $isProduction
    ? ['vameri@gmail.com']
    : ['demo@ethernity.com', 'vameri@gmail.com'];

$devOrigins = $isProduction ? [] : [
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://localhost:3333',
    'http://127.0.0.1:3333',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:28080',
    'http://127.0.0.1:28080',
];

$allowedOrigins = array_values(array_unique(array_merge(
    array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', implode(',', array_filter([
            (string) env('CORS_ORIGINS', ''),
            (string) env('CLIENT_URL', ''),
            (string) env('FRONTEND_URL', ''),
            (string) env('APP_URL', ''),
        ]))),
    ))),
    $devOrigins,
)));

$appUrl = rtrim(trim((string) env('APP_URL', '')) ?: 'http://localhost:8000', '/');
$frontendUrl = rtrim(trim(explode(',', trim((string) env('FRONTEND_URL', '')) ?: trim((string) env('CLIENT_URL', '')) ?: $appUrl)[0]), '/');

$envJwtSecret = trim((string) env('JWT_SECRET', ''));
$jwtSecret = $envJwtSecret !== ''
    ? $envJwtSecret
    : ($isProduction ? '' : 'eternity-local-development-only-change-before-production-2026');

$envVaultKey = trim((string) env('VAULT_MASTER_KEY', ''));
$vaultMasterKey = $envVaultKey !== ''
    ? $envVaultKey
    : ($isProduction ? $envJwtSecret : ($envJwtSecret !== '' ? $envJwtSecret : 'ethernity-vault-secret-key-2026'));

return [
    'version' => '1.0.1',

    // One public address (APP_URL) is enough: local and production differ only in .env. Empty values fall back to it.
    'frontend_url' => $frontendUrl,

    'allowed_origins' => $allowedOrigins,

    'jwt' => [
        'secret' => $jwtSecret,
        'algorithm' => 'HS256',
        'ttl_seconds' => (int) env('JWT_TTL_SECONDS', 86400),
    ],

    'vault' => [
        'disk' => (string) env('VAULT_DISK', 'vault'),
        'master_key' => $vaultMasterKey,
        'chunk_size' => 65536,
        'max_media_bytes' => (int) env('VAULT_MAX_MEDIA_BYTES', 150 * 1024 * 1024),
        'min_kdf_iterations' => 600000,
        'max_kdf_iterations' => 10000000,
    ],

    'uploads' => [
        'disk' => (string) env('UPLOADS_DISK', 'uploads'),
        'max_bytes' => (int) env('MAX_UPLOAD_BYTES', 50 * 1024 * 1024),
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif',
            'image/avif',
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-matroska',
            'video/ogg',
            'audio/mpeg',
            'audio/wav',
            'audio/webm',
            'audio/ogg',
            'audio/aac',
            'audio/mp4',
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/pdf',
            'application/json',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    // Dados do responsável exibidos em /privacidade e /termos. Vazios = a página mostra
    // "a definir" (preencher antes de publicar o app OAuth / enviar à verificação do Google).
    'legal' => [
        'entity_name' => trim((string) env('LEGAL_ENTITY_NAME', '')),
        'entity_document' => trim((string) env('LEGAL_ENTITY_DOCUMENT', '')),
        'privacy_email' => trim((string) env('PRIVACY_CONTACT_EMAIL', '')),
        'updated_at' => '2026-11-21',
    ],

    'security' => [
        'min_password_length' => 8,
        'root_admin_emails' => array_values(array_unique(array_merge($defaultRoots, $extraAdmins))),
        'demo_login_enabled' => ! $isProduction || env('ENABLE_DEMO_LOGIN') === '1',
        'payment_simulation_enabled' => ! $isProduction || env('ENABLE_PAYMENT_SIMULATION') === '1',
        // API documentation (/docs/api, /docs/api.json): always open locally; elsewhere only with API_DOCS_PUBLIC=1
        'api_docs_public' => env('API_DOCS_PUBLIC') === '1',
        'rate_limit_enabled' => env('RATE_LIMIT_ENABLED') !== null
            ? env('RATE_LIMIT_ENABLED') === '1'
            : $isProduction,
    ],

    // Native iOS app: longer-lived token so background uploads keep working
    'ios' => [
        'token_ttl_seconds' => (int) env('IOS_TOKEN_TTL_SECONDS', 30 * 24 * 60 * 60),
    ],

    'google' => [
        'client_id' => (string) env('GOOGLE_PHOTOS_CLIENT_ID', (string) env('GOOGLE_CLIENT_ID', '')),
        'client_secret' => (string) env('GOOGLE_PHOTOS_CLIENT_SECRET', (string) env('GOOGLE_CLIENT_SECRET', '')),
        'redirect_uri' => trim((string) env('GOOGLE_PHOTOS_REDIRECT_URI', ''))
            ?: trim((string) env('GOOGLE_REDIRECT_URI', ''))
            ?: $appUrl.'/api/integrations/google-photos/callback',
        'token_encryption_key' => (string) env('GOOGLE_PHOTOS_TOKEN_ENCRYPTION_KEY', ''),
        'state_ttl_seconds' => (int) env('GOOGLE_PHOTOS_STATE_TTL_SECONDS', 600),
        'max_file_size_bytes' => (int) env('GOOGLE_PHOTOS_MAX_FILE_SIZE_MB', 50) * 1024 * 1024,
        'max_items_per_session' => (int) env('GOOGLE_PHOTOS_MAX_ITEMS_PER_SESSION', 50),
    ],

    'mercado_pago' => [
        'access_token' => (string) env('MERCADO_PAGO_ACCESS_TOKEN', ''),
        'public_key' => (string) env('MERCADO_PAGO_PUBLIC_KEY', ''),
        'webhook_secret' => (string) env('MERCADO_PAGO_WEBHOOK_SECRET', ''),
        'api_base' => rtrim((string) env('MERCADO_PAGO_API_BASE', 'https://api.mercadopago.com'), '/'),
        // Where Mercado Pago sends payment notifications (must be public HTTPS; ignored otherwise).
        'notification_url' => trim((string) env('MERCADO_PAGO_NOTIFICATION_URL', ''))
            ?: $appUrl.'/api/subscriptions/webhook',
        // Where the payer returns after the hosted subscription checkout.
        'back_url' => trim((string) env('MERCADO_PAGO_BACK_URL', '')) ?: $frontendUrl.'/',
        'pix_expiration_minutes' => (int) env('MERCADO_PAGO_PIX_EXPIRATION_MINUTES', 30),
        // Recurring charges that fail are retried by Mercado Pago for up to ~10 days before the
        // subscription is canceled, so auto-renewing plans keep access during that window.
        'renewal_grace_days' => (int) env('MERCADO_PAGO_RENEWAL_GRACE_DAYS', 10),
        'http_timeout_seconds' => (int) env('MERCADO_PAGO_HTTP_TIMEOUT', 15),
    ],

    'plans' => [
        'free' => [
            'id' => 'free',
            'name' => 'Gratuito / Essencial',
            'priceMonthly' => 0,
            'priceYearly' => 0,
            'maxCapsules' => 5,
            'maxGuardians' => 2,
            'storageGb' => 5,
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => 30,
            'features' => [
                'Até 5 Cápsulas do Tempo',
                '2 Guardiões de Confiança',
                '5 GB no Cofre Criptografado',
                'Criptografia Zero-Knowledge AES-256-GCM',
            ],
        ],
        'family' => [
            'id' => 'family',
            'name' => 'Legado Familiar',
            'priceMonthly' => 29.90,
            'priceYearly' => 299.00,
            'maxCapsules' => 50,
            'maxGuardians' => 10,
            'storageGb' => 100,
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => 365,
            'popular' => true,
            'features' => [
                'Até 50 Cápsulas do Tempo',
                'Até 10 Guardiões de Confiança',
                '100 GB no Cofre Criptografado',
                'Narração e Gravação com Foto Original',
                'Assistente de Ideação de Cartas com IA Local',
                'Suporte a Apple Live Photos e Formato .HEIC',
            ],
        ],
        'clinical' => [
            'id' => 'clinical',
            'name' => 'Patrimonial & Eterno',
            'priceMonthly' => 79.90,
            'priceYearly' => 799.00,
            'maxCapsules' => 9999,
            'maxGuardians' => 9999,
            'storageGb' => 1000,
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => 9999,
            'features' => [
                'Cápsulas e Cartas Ilimitadas',
                'Guardiões de Confiança Ilimitados',
                '1 TB de Armazenamento Seguro',
                'Protocolo Dead Man Switch com Quórum Avançado',
                'Exportação Gráfica Fine-Art para Impressão 300 DPI',
                'Suporte e Consultoria de Legado Prioritário 24/7',
            ],
        ],
        'infinity' => [
            'id' => 'infinity',
            'name' => 'Plano Infinity (Liberado Tudo)',
            'priceMonthly' => 0,
            'priceYearly' => 0,
            'maxCapsules' => 9999,
            'maxGuardians' => 9999,
            'storageGb' => 9999,
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => 9999,
            'features' => [
                'Criação Ilimitada de Cápsulas e Destinatários',
                'Guardiões e Cofre Ilimitados',
                'Acesso Irrestrito a Todos os Recursos e IA',
                'Armazenamento Perpétuo com Criptografia Militar',
            ],
        ],
    ],
];
