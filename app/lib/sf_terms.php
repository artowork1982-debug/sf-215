<?php

/**
 * SafetyFlash term helpers.
 *
 * Lataa keskitetty termikonfiguraatio tiedostosta
 * app/config/safetyflash_terms.php ja tarjoaa helperit:
 *
 *   - sf_get_terms_config(): array
 *   - sf_terms(): array
 *   - sf_term(string $key, string $lang = 'fi', ?string $fallbackLang = 'fi'): string
 *   - sf_supported_languages(): array
 */

if (!function_exists('sf_get_terms_config')) {
    function sf_get_terms_config(): array
    {
        static $config = null;

        if ($config === null) {
            $config = require __DIR__ . '/../config/safetyflash_terms.php';

            if (!is_array($config)) {
                $config = [
                    'languages' => ['fi'],
                    'terms'     => [],
                ];
            }

            if (!isset($config['languages']) || !is_array($config['languages'])) {
                $config['languages'] = ['fi'];
            }

            if (!isset($config['terms']) || !is_array($config['terms'])) {
                $config['terms'] = [];
            }
        }

        return $config;
    }
}

if (!function_exists('sf_terms')) {
    /**
     * Palauttaa termisanaston "terms"-osion.
     *
     * @return array<string,array<string,string>>
     */
    function sf_terms(): array
    {
        $config = sf_get_terms_config();
        return $config['terms'] ?? [];
    }
}

if (!function_exists('sf_term')) {
    /**
     * Hae Safetyflash-termi.
     *
     * @param string      $key          esim. 'dangerous_situation'
     * @param string      $lang         kielikoodi, esim. 'fi', 'sv', 'en', 'it', 'el'
     * @param string|null $fallbackLang varakieli, oletuksena 'fi'
     */
    function sf_term(string $key, string $lang = 'fi', ?string $fallbackLang = 'fi'): string
    {
        $terms = sf_terms();

        if (!isset($terms[$key]) || !is_array($terms[$key])) {
            // debug: palautetaan avain jos puuttuu
            return $key;
        }

        $entry = $terms[$key];

        if (!empty($entry[$lang])) {
            return $entry[$lang];
        }

        if ($fallbackLang && !empty($entry[$fallbackLang])) {
            return $entry[$fallbackLang];
        }

        // fallback: englanti → suomi → mikä tahansa arvo
        if (!empty($entry['en'])) {
            return $entry['en'];
        }

        if (!empty($entry['fi'])) {
            return $entry['fi'];
        }

        foreach ($entry as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return $key;
    }
}

if (!function_exists('sf_supported_languages')) {
    /**
     * Palauttaa tuetut kielikoodit.
     *
     * @return string[]
     */
    function sf_supported_languages(): array
    {
        $config = sf_get_terms_config();
        return $config['languages'] ?? ['fi'];
    }
}

if (!function_exists('sf_role_name')) {
    /**
     * Käännä roolin nimi UI-kielelle
     * 
     * @param int $roleId Roolin ID tietokannasta
     * @param string $roleName Roolin nimi tietokannasta (fallback)
     * @param string $lang UI-kieli (fi, sv, en, it, el)
     * @return string Käännetty roolin nimi
     */
    function sf_role_name(int $roleId, string $roleName, string $lang = 'fi'): string
    {
        // Mäppää role_id -> termiavain
        $roleKeyMap = [
            1 => 'role_admin',
            2 => 'role_user', 
            3 => 'role_safety_team',
            4 => 'role_comms',
            5 => 'role_distribution_fi',
            6 => 'role_distribution_sv',
            7 => 'role_distribution_en',
            8 => 'role_distribution_it',
            9 => 'role_distribution_el',
        ];
        
        if (isset($roleKeyMap[$roleId])) {
            $translated = sf_term($roleKeyMap[$roleId], $lang);
            // Palauta käännös vain jos se löytyi (ei palauta avainta)
            if ($translated !== $roleKeyMap[$roleId]) {
                return $translated;
            }
        }
        
        // Fallback: palauta tietokannan nimi
        return $roleName;
    }
}