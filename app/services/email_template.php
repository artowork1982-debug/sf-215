<?php
// app/services/email_template.php

declare(strict_types=1);

/**
 * HTML Email Template Service for SafetyFlash
 * 
 * Generates modern, responsive HTML emails with multi-language support.
 * Includes plain text alternative for multipart emails.
 */

// Type colors and icons
const EMAIL_TYPE_COLORS = [
    'red'    => '#dc2626',  // Red - personal injury
    'yellow' => '#ca8a04',  // Yellow - equipment damage
    'green'  => '#16a34a',  // Green - investigation
];

const EMAIL_TYPE_ICONS = [
    'red'    => '🔴',
    'yellow' => '🟡',
    'green'  => '🟢',
];

/**
 * Get translation term
 */
function sf_email_term(string $key, string $lang = 'fi'): string
{
    static $terms = null;
    if ($terms === null) {
        $termsData = require __DIR__ . '/../config/safetyflash_terms.php';
        $terms = $termsData['terms'] ?? [];
    }
    
    return $terms[$key][$lang] ?? $terms[$key]['fi'] ?? $key;
}

/**
 * Generate HTML email template
 * 
 * @param array $data Email data
 *   - type: red|yellow|green
 *   - flash_id: SafetyFlash ID
 *   - subject: Email subject/heading
 *   - body_text: Main message body
 *   - flash_title: SafetyFlash title (optional)
 *   - flash_worksite: Worksite name (optional)
 *   - flash_url: Direct link to SafetyFlash (optional)
 *   - message: Additional message (optional)
 *   - message_label: Label for additional message (optional)
 *   - translations: Array of language versions ['lang' => url] (optional)
 * @param string $lang Language code (fi, sv, en, it, el)
 * @return string HTML content
 */
function sf_generate_email_html(array $data, string $lang = 'fi'): string
{
    // Check if this is a welcome email
    $isWelcome = ($data['type'] ?? '') === 'welcome';
    
    if ($isWelcome) {
        return sf_generate_welcome_email_html($data, $lang);
    }
    
    // Extract data
    $type = $data['type'] ?? 'yellow';
    $flashId = $data['flash_id'] ?? '';
    $subject = $data['subject'] ?? '';
    $bodyText = $data['body_text'] ?? '';
    $flashTitle = $data['flash_title'] ?? '';
    $flashWorksite = $data['flash_worksite'] ?? '';
    $flashUrl = $data['flash_url'] ?? '';
    $message = $data['message'] ?? '';
    $messageLabel = $data['message_label'] ?? '';
    $translations = $data['translations'] ?? [];
    
    // Get translations
    $greeting = sf_email_term('email_greeting', $lang);
    $signature = sf_email_term('email_signature', $lang);
    $systemName = sf_email_term('email_system_name', $lang);
    $ctaText = sf_email_term('email_open_safetyflash', $lang);
    $labelId = sf_email_term('email_flash_id', $lang);
    $labelType = sf_email_term('email_flash_type', $lang);
    $labelTitle = sf_email_term('email_flash_title', $lang);
    $labelWorksite = sf_email_term('email_flash_worksite', $lang);
    
    // Get type info
    $typeColor = EMAIL_TYPE_COLORS[$type] ?? EMAIL_TYPE_COLORS['yellow'];
    $typeIcon = EMAIL_TYPE_ICONS[$type] ?? EMAIL_TYPE_ICONS['yellow'];
    $typeName = sf_email_term("email_type_{$type}", $lang);
    
    // Logo URL - can be configured via config or settings
    // For now using a simple text-based header instead of image for better compatibility
    $logoUrl = '';
    
    // Build HTML
    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">
                    <!-- Header with logo -->
                    <tr>
                        <td style="background:#0f172a;padding:20px;text-align:center;">
                            <!-- Tapojärvi logo URL as specified in requirements -->
                            <img src="https://tapojarvi.online/safetyflash-system/assets/img/tapojarvi_logo.png" 
                                 alt="Tapojärvi" 
                                 style="max-width:200px;height:auto;">
                        </td>
                    </tr>
                    
                    <!-- Type badge (color based on type) -->
                    <tr>
                        <td style="background:{$typeColor};color:#fff;padding:12px 20px;font-weight:bold;font-size:14px;">
                            {$typeIcon} {$typeName}
HTML;

    if ($flashId) {
        $html .= " | {$labelId}: {$flashId}";
    }

    $html .= <<<HTML

                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding:30px;">
                            <h2 style="margin:0 0 20px;color:#0f172a;font-size:20px;">{$greeting},</h2>
                            <p style="color:#475569;line-height:1.6;margin:0 0 20px;">{$bodyText}</p>
HTML;

    // Flash details box
    if ($flashTitle || $flashWorksite) {
        $html .= <<<HTML

                            
                            <table style="background:#f8fafc;border-radius:8px;padding:16px;width:100%;margin:20px 0;border-collapse:collapse;">
HTML;
        
        if ($flashTitle) {
            $html .= <<<HTML

                                <tr>
                                    <td style="padding:8px 0;"><strong style="color:#0f172a;">{$labelTitle}:</strong> <span style="color:#475569;">{$flashTitle}</span></td>
                                </tr>
HTML;
        }
        
        if ($flashWorksite) {
            $html .= <<<HTML

                                <tr>
                                    <td style="padding:8px 0;"><strong style="color:#0f172a;">{$labelWorksite}:</strong> <span style="color:#475569;">{$flashWorksite}</span></td>
                                </tr>
HTML;
        }
        
        $html .= <<<HTML

                            </table>
HTML;
    }

    // Additional message box
    if ($message && $messageLabel) {
        $messageHtml = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $html .= <<<HTML

                            
                            <div style="background:#fef3c7;border-left:4px solid #ca8a04;padding:16px;margin:20px 0;border-radius:4px;">
                                <strong style="color:#92400e;display:block;margin-bottom:8px;">{$messageLabel}:</strong>
                                <p style="color:#78350f;margin:0;line-height:1.6;">{$messageHtml}</p>
                            </div>
HTML;
    }

    // Language versions (translations)
    if (!empty($translations)) {
        // Map language codes to flag emojis
        $langFlags = [
            'fi' => '🇫🇮',
            'sv' => '🇸🇪',
            'en' => '🇬🇧',
            'it' => '🇮🇹',
            'el' => '🇬🇷',
        ];
        
        $availableLabel = sf_email_term('email_available_languages', $lang);
        $langLinks = [];
        
        foreach ($translations as $tlang => $url) {
            $flag = $langFlags[$tlang] ?? '';
            $langName = sf_email_term("lang_name_{$tlang}", $lang);
            $langLinks[] = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="color:#0f172a;text-decoration:none;font-weight:500;">' . $flag . ' ' . htmlspecialchars($langName, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        
        if (!empty($langLinks)) {
            $linksHtml = implode(' | ', $langLinks);
            $html .= <<<HTML

                            
                            <div style="background:#f1f5f9;border-radius:8px;padding:16px;margin:20px 0;">
                                <p style="color:#475569;margin:0;line-height:1.6;">
                                    <strong style="color:#0f172a;">{$availableLabel}:</strong><br>
                                    {$linksHtml}
                                </p>
                            </div>
HTML;
        }
    }

    // CTA Button
    if ($flashUrl) {
        $html .= <<<HTML

                            
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$flashUrl}" style="background:#0f172a;color:#fff;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">{$ctaText}</a>
                                    </td>
                                </tr>
                            </table>
HTML;
    }

    $html .= <<<HTML

                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8fafc;padding:20px;text-align:center;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">
                            <!-- Safety logo URL as specified in requirements -->
                            <img src="https://tapojarvi.online/safetyflash-system/assets/img/safetylogo.png" 
                                 alt="Safety is our value" 
                                 style="max-width:150px;height:auto;margin-bottom:15px;">
                            <p style="font-size:11px;color:#9ca3af;font-style:italic;margin:20px 0 0 0;border-top:1px solid #e5e7eb;padding-top:15px;">
HTML;
    
    $html .= sf_email_term('email_do_not_reply', $lang);
    
    $html .= <<<HTML

                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return $html;
}

/**
 * Generate welcome email HTML for new users
 * 
 * @param array $data Email data
 *   - subject: Email subject
 *   - body_text: Welcome message
 *   - user_name: User's full name
 *   - user_email: User's email
 *   - user_role: User's role
 *   - generated_password: Generated password
 *   - login_url: Login URL
 *   - instructions: Password change instructions
 * @param string $lang Language code (fi, sv, en, it, el)
 * @return string HTML content
 */
function sf_generate_welcome_email_html(array $data, string $lang = 'fi'): string
{
    $subject = $data['subject'] ?? '';
    $bodyText = $data['body_text'] ?? '';
    $userName = $data['user_name'] ?? '';
    $userEmail = $data['user_email'] ?? '';
    $userRole = $data['user_role'] ?? '';
    $password = $data['generated_password'] ?? '';
    $loginUrl = $data['login_url'] ?? '';
    $instructions = $data['instructions'] ?? '';
    
    // Get translations
    $greeting = sf_email_term('email_welcome_greeting', $lang);
    $passwordLabel = sf_email_term('email_welcome_password_label', $lang);
    $emailLabel = sf_email_term('email_your_email', $lang);
    $roleLabel = sf_email_term('email_your_role', $lang);
    $loginButton = sf_email_term('email_welcome_login_button', $lang);
    
    // Welcome color scheme - blue/green (safe, positive)
    $headerColor = '#0f766e'; // Teal
    $accentColor = '#0ea5e9'; // Sky blue
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;">
                    <!-- Header with logo -->
                    <tr>
                        <td style="background:#0f172a;padding:20px;text-align:center;">
                            <img src="https://tapojarvi.online/safetyflash-system/assets/img/tapojarvi_logo.png" 
                                 alt="Tapojärvi" 
                                 style="max-width:200px;height:auto;">
                        </td>
                    </tr>
                    
                    <!-- Welcome header -->
                    <tr>
                        <td style="background:{$headerColor};color:#fff;padding:20px;text-align:center;">
                            <h1 style="margin:0;font-size:24px;font-weight:bold;">{$subject}</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding:30px;">
                            <h2 style="margin:0 0 20px;color:#0f172a;font-size:20px;">{$greeting} {$userName},</h2>
                            <p style="color:#475569;line-height:1.6;margin:0 0 20px;">{$bodyText}</p>
                            
                            <!-- User credentials box -->
                            <table style="background:#f8fafc;border-radius:8px;padding:16px;width:100%;margin:20px 0;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:8px 0;"><strong style="color:#0f172a;">{$emailLabel}</strong> <span style="color:#475569;">{$userEmail}</span></td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;"><strong style="color:#0f172a;">{$roleLabel}</strong> <span style="color:#475569;">{$userRole}</span></td>
                                </tr>
                            </table>
                            
                            <!-- Password box -->
                            <div style="background:#fef3c7;border:2px solid #fbbf24;border-radius:8px;padding:20px;margin:20px 0;text-align:center;">
                                <p style="margin:0 0 10px;color:#78350f;font-weight:bold;font-size:14px;">{$passwordLabel}</p>
                                <div style="background:#fff;border-radius:6px;padding:15px;margin:10px 0;">
                                    <code style="font-size:20px;font-weight:bold;color:#0f172a;letter-spacing:2px;font-family:monospace;">{$password}</code>
                                </div>
                            </div>
                            
                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$loginUrl}" style="background:{$accentColor};color:#fff;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">{$loginButton}</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Security notice -->
                            <div style="background:#e0f2fe;border-left:4px solid {$accentColor};padding:16px;margin:20px 0;border-radius:4px;">
                                <p style="color:#075985;margin:0;line-height:1.6;font-size:14px;">
                                    <strong>ℹ️</strong> {$instructions}
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8fafc;padding:20px;text-align:center;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">
                            <img src="https://tapojarvi.online/safetyflash-system/assets/img/safetylogo.png" 
                                 alt="Safety is our value" 
                                 style="max-width:150px;height:auto;margin-bottom:15px;">
                            <p style="font-size:11px;color:#9ca3af;font-style:italic;margin:20px 0 0 0;border-top:1px solid #e5e7eb;padding-top:15px;">
HTML;
    
    $html .= sf_email_term('email_do_not_reply', $lang);
    
    $html .= <<<HTML

                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return $html;
}

/**
 * Generate plain text version of email
 * 
 * @param array $data Email data (same as sf_generate_email_html)
 * @param string $lang Language code
 * @return string Plain text content
 */
function sf_generate_email_text(array $data, string $lang = 'fi'): string
{
    // Check if this is a welcome email
    $isWelcome = ($data['type'] ?? '') === 'welcome';
    
    if ($isWelcome) {
        return sf_generate_welcome_email_text($data, $lang);
    }
    
    $type = $data['type'] ?? 'yellow';
    $flashId = $data['flash_id'] ?? '';
    $subject = $data['subject'] ?? '';
    $bodyText = $data['body_text'] ?? '';
    $flashTitle = $data['flash_title'] ?? '';
    $flashWorksite = $data['flash_worksite'] ?? '';
    $flashUrl = $data['flash_url'] ?? '';
    $message = $data['message'] ?? '';
    $messageLabel = $data['message_label'] ?? '';
    $translations = $data['translations'] ?? [];
    
    // Get translations
    $greeting = sf_email_term('email_greeting', $lang);
    $signature = sf_email_term('email_signature', $lang);
    $systemName = sf_email_term('email_system_name', $lang);
    $ctaText = sf_email_term('email_open_safetyflash', $lang);
    $labelId = sf_email_term('email_flash_id', $lang);
    $labelTitle = sf_email_term('email_flash_title', $lang);
    $labelWorksite = sf_email_term('email_flash_worksite', $lang);
    $typeName = sf_email_term("email_type_{$type}", $lang);
    
    // Build plain text
    $text = "SafetyFlash\n";
    $text .= str_repeat("=", 50) . "\n\n";
    
    $text .= "{$typeName}";
    if ($flashId) {
        $text .= " | {$labelId}: {$flashId}";
    }
    $text .= "\n";
    $text .= str_repeat("-", 50) . "\n\n";
    
    $text .= "{$greeting},\n\n";
    $text .= "{$bodyText}\n\n";
    
    // Flash details
    if ($flashTitle || $flashWorksite) {
        $text .= str_repeat("-", 50) . "\n";
        if ($flashTitle) {
            $text .= "{$labelTitle}: {$flashTitle}\n";
        }
        if ($flashWorksite) {
            $text .= "{$labelWorksite}: {$flashWorksite}\n";
        }
        $text .= str_repeat("-", 50) . "\n\n";
    }
    
    // Additional message
    if ($message && $messageLabel) {
        $text .= "{$messageLabel}:\n{$message}\n\n";
    }
    
    // Language versions
    if (!empty($translations)) {
        $availableLabel = sf_email_term('email_available_languages', $lang);
        $langLinks = [];
        
        foreach ($translations as $tlang => $url) {
            $langName = sf_email_term("lang_name_{$tlang}", $lang);
            $langLinks[] = "{$langName}: {$url}";
        }
        
        if (!empty($langLinks)) {
            $text .= "{$availableLabel}:\n";
            $text .= implode("\n", $langLinks) . "\n\n";
        }
    }
    
    // Link
    if ($flashUrl) {
        $text .= "{$ctaText}:\n{$flashUrl}\n\n";
    }
    
    $text .= "{$signature},\n{$systemName}\n";
    
    // Do not reply notice
    $text .= "\n---\n";
    $text .= sf_email_term('email_do_not_reply', $lang);
    
    return $text;
}

/**
 * Generate plain text version of welcome email
 * 
 * @param array $data Email data
 * @param string $lang Language code
 * @return string Plain text content
 */
function sf_generate_welcome_email_text(array $data, string $lang = 'fi'): string
{
    $subject = $data['subject'] ?? '';
    $bodyText = $data['body_text'] ?? '';
    $userName = $data['user_name'] ?? '';
    $userEmail = $data['user_email'] ?? '';
    $userRole = $data['user_role'] ?? '';
    $password = $data['generated_password'] ?? '';
    $loginUrl = $data['login_url'] ?? '';
    $instructions = $data['instructions'] ?? '';
    
    // Get translations
    $greeting = sf_email_term('email_welcome_greeting', $lang);
    $passwordLabel = sf_email_term('email_welcome_password_label', $lang);
    $emailLabel = sf_email_term('email_your_email', $lang);
    $roleLabel = sf_email_term('email_your_role', $lang);
    $loginButton = sf_email_term('email_welcome_login_button', $lang);
    $systemName = sf_email_term('email_system_name', $lang);
    
    // Build plain text
    $text = "SafetyFlash\n";
    $text .= str_repeat("=", 50) . "\n\n";
    
    $text .= "{$subject}\n";
    $text .= str_repeat("-", 50) . "\n\n";
    
    $text .= "{$greeting} {$userName},\n\n";
    $text .= "{$bodyText}\n\n";
    
    // User details
    $text .= str_repeat("-", 50) . "\n";
    $text .= "{$emailLabel} {$userEmail}\n";
    $text .= "{$roleLabel} {$userRole}\n";
    $text .= str_repeat("-", 50) . "\n\n";
    
    // Password
    $text .= "{$passwordLabel}\n";
    $text .= "    {$password}\n\n";
    
    // Link
    $text .= "{$loginButton}:\n{$loginUrl}\n\n";
    
    // Instructions
    $text .= "ℹ️  {$instructions}\n\n";
    
    // Footer
    $text .= str_repeat("=", 50) . "\n";
    $text .= "{$systemName}\n\n";
    
    // Do not reply notice
    $text .= "---\n";
    $text .= sf_email_term('email_do_not_reply', $lang);
    
    return $text;
}