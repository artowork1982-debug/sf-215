<?php
require_once __DIR__ . '/config.php';

// Set correct content type for manifest
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400'); // Cache 24h

$base = rtrim($config['base_url'], '/');

$manifest = [
    "name" => "SafetyFlash - Tapojärvi",
    "short_name" => "SafetyFlash",
    "description" => "Tapojärvi SafetyFlash -turvallisuusilmoitusjärjestelmä",
    "start_url" => "{$base}/index.php?page=list",
    "display" => "standalone",
    "orientation" => "portrait-primary",
    "theme_color" => "#0f172a",
    "background_color" => "#0f172a",
    "lang" => "fi",
    "scope" => "{$base}/",
    "icons" => [
        // Any-ikonit (näytetään sellaisenaan)
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-192.png",
            "sizes" => "192x192",
            "type" => "image/png",
            "purpose" => "any"
        ],
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-512.png",
            "sizes" => "512x512",
            "type" => "image/png",
            "purpose" => "any"
        ],
        // Maskable-ikonit (rajataan ympyräksi/muodoksi)
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-maskable-192.png",
            "sizes" => "192x192",
            "type" => "image/png",
            "purpose" => "maskable"
        ],
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-maskable-512.png",
            "sizes" => "512x512",
            "type" => "image/png",
            "purpose" => "maskable"
        ]
    ],
    "categories" => ["business", "productivity"],
    "shortcuts" => [
        [
            "name" => "Uusi SafetyFlash",
            "short_name" => "Uusi",
            "url" => "{$base}/index.php?page=form",
            "icons" => [
                [
                    "src" => "{$base}/assets/img/icons/add_new_icon.png",
                    "sizes" => "96x96",
                    "type" => "image/png"
                ]
            ]
        ],
        [
            "name" => "Lista",
            "url" => "{$base}/index.php?page=list",
            "icons" => [
                [
                    "src" => "{$base}/assets/img/icons/list_icon.png",
                    "sizes" => "96x96",
                    "type" => "image/png"
                ]
            ]
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);