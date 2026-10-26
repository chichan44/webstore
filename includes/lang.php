<?php
/**
 * Lightweight i18n system for Digital Store.
 *
 * Usage:
 *   echo t('Produk Digital');
 *   echo t('Stok {name} habis', ['name' => $productName]);
 *
 * Locale resolution order:
 *   1. ?lang=xx query parameter (stored in session)
 *   2. $_SESSION['lang']
 *   3. setting('site_default_lang')
 *   4. 'id' (fallback)
 */

$__LANG_CACHE = [];
$__LANG_CURRENT = null;

function lang_available(): array {
    return ['id' => 'Indonesia', 'en' => 'English'];
}

function lang_resolve(): string {
    global $__LANG_CURRENT;
    if ($__LANG_CURRENT !== null) return $__LANG_CURRENT;

    // 1. Query parameter (persist to session)
    if (isset($_GET['lang']) && $_GET['lang'] !== '') {
        $requested = strtolower(trim($_GET['lang']));
        if (in_array($requested, array_keys(lang_available()), true)) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['lang'] = $requested;
            $__LANG_CURRENT = $requested;
            return $__LANG_CURRENT;
        }
    }

    // 2. Session
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang'])) {
        $session = strtolower(trim($_SESSION['lang']));
        if (in_array($session, array_keys(lang_available()), true)) {
            $__LANG_CURRENT = $session;
            return $__LANG_CURRENT;
        }
    }

    // 3. Site setting
    if (function_exists('setting')) {
        $default = strtolower(trim((string)setting('site_default_lang', '')));
        if (in_array($default, array_keys(lang_available()), true)) {
            $__LANG_CURRENT = $default;
            return $__LANG_CURRENT;
        }
    }

    // 4. Fallback
    $__LANG_CURRENT = 'id';
    return $__LANG_CURRENT;
}

function lang_load(string $locale): array {
    global $__LANG_CACHE;
    if (isset($__LANG_CACHE[$locale])) return $__LANG_CACHE[$locale];

    $file = __DIR__ . '/../lang/' . $locale . '.json';
    if (!file_exists($file)) {
        $__LANG_CACHE[$locale] = [];
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    $__LANG_CACHE[$locale] = is_array($data) ? $data : [];
    return $__LANG_CACHE[$locale];
}

/**
 * Translate a string.
 *
 * @param string $key     The string key (Indonesian original text)
 * @param array  $replacements  Optional {placeholder} => value pairs
 * @param string|null $locale   Force locale (default: auto-detect)
 * @return string Translated string
 */
function t(string $key, array $replacements = [], ?string $locale = null): string {
    if ($key === '') return '';

    $locale = $locale ?? lang_resolve();
    $translations = lang_load($locale);

    // Look up translation
    $translated = $key;
    if ($locale !== 'id' && isset($translations[$key])) {
        $translated = $translations[$key];
    }

    // Interpolate placeholders
    if ($replacements) {
        $search = [];
        $replace = [];
        foreach ($replacements as $placeholder => $value) {
            $search[] = '{' . $placeholder . '}';
            $replace[] = (string)$value;
        }
        $translated = str_replace($search, $replace, $translated);
    }

    return $translated;
}

/**
 * Get current locale code.
 */
function current_lang(): string {
    return lang_resolve();
}

/**
 * Check if current locale is English.
 */
function is_english(): bool {
    return lang_resolve() === 'en';
}

/**
 * Language switcher HTML for frontend header.
 */
function render_lang_switcher(): string {
    $current = lang_resolve();
    $available = lang_available();
    $html = '<div class="lang-switcher">';
    $html .= '<button type="button" class="head-chip lang-btn" data-open-lang aria-label="Switch language"><span>🌐</span><small>' . strtoupper($current) . '</small></button>';
    $html .= '<div class="lang-popover" id="langPopover">';
    foreach ($available as $code => $name) {
        $active = $code === $current ? ' active' : '';
        $html .= '<a href="?lang=' . $code . '" class="lang-option' . $active . '"><b>' . $name . '</b><small>' . strtoupper($code) . '</small></a>';
    }
    $html .= '</div></div>';
    return $html;
}
?>
