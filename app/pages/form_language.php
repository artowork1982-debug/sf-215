<?php
// app/pages/form_language.php
declare(strict_types=1);

require_once __DIR__ .'/../includes/protect.php';


// --- DB: PDO (sama kuin view.php:ssa) ---
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    echo '<p>Tietokantavirhe (form_language)</p>';
    exit;
}

$fromId = isset($_GET['from_id']) ? (int) $_GET['from_id'] : 0;
$newLang = isset($_GET['lang']) ? trim($_GET['lang']) : '';

if ($fromId <= 0 || $newLang === '') {
    echo '<div class="sf-error">Virhe: kieliversion luomiseen tarvitaan from_id ja lang.</div>';
    return;
}

// Hae pohjaflash kannasta
$stmt = $pdo->prepare('SELECT * FROM sf_flashes WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $fromId]);
$baseFlash = $stmt->fetch();

if (!$baseFlash) {
    echo '<div class="sf-error">Virhe: pohjatiedotetta ei löytynyt.</div>';
    return;
}

// Määritä translation_group_id
if (!empty($baseFlash['translation_group_id'])) {
    $translationGroupId = (int) $baseFlash['translation_group_id'];
} else {
    $translationGroupId = (int) $baseFlash['id'];
}


$supportedLangs = [
    'fi' => 'Suomi',
    'sv' => 'Ruotsi',
    'en' => 'Englanti',
    'it' => 'Italia',
    'el' => 'Kreikka',
];

$langLabel = $supportedLangs[$newLang] ?? strtoupper($newLang);
?>

<!-- === VAIHE 2: PREVIEW (modaalissa) === -->
<div class="sf-translation-preview-wrapper">
    <div id="sfTranslationPreviewContainer">
        <?php
        // Valmistele kuvainfo preview-modaaliin
        $flash = $baseFlash; // Käytä pohjaflashia!
        require __DIR__ .'/../partials/preview_modal.php';
        ?>
    </div>
</div>

<div class="sf-form-container sf-form-language">

    <div class="sf-translation-banner">
        <div class="sf-translation-banner-title">
            Kieliversio: <strong><?php echo htmlspecialchars($langLabel); ?></strong>
        </div>
        <div class="sf-translation-banner-meta">
            Perustiedote ID #<?php echo (int) $baseFlash['id']; ?> ·
            Tyyppi: <?php echo htmlspecialchars($baseFlash['type']); ?> ·
            Työmaa: <?php echo htmlspecialchars($baseFlash['site']); ?> ·
            Tapahtuma-aika: <?php echo htmlspecialchars($baseFlash['occurred_at']); ?>
        </div>
        <div class="sf-translation-banner-info">
            Tämä näkymä on tarkoitettu käännösten ja kielenhuollon tekemiseen
            valmiille safetyflashille. Perustiedot (tyyppi, työmaa, tapahtuma-aika, kuvat)
            on lukittu pohjatiedotteen mukaisiksi, eikä niitä muuteta.
        </div>
    </div>

    <form class="sf-form" method="post" action="<?php echo $config['base_url']; ?>/app/api/save_translation.php">
        <input type="hidden" name="from_id" value="<?php echo (int) $fromId; ?>">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($newLang); ?>">
        <input type="hidden" name="translation_group_id" value="<?php echo (int) $translationGroupId; ?>">
        <input type="hidden" name="preview_image" id="preview_image">

        <!-- Perustietojen “snapshot” (vain luettavaksi) -->
        <div class="sf-form-section sf-form-language-meta">
            <h2>Perustiedot (lukittu)</h2>
            <div class="sf-form-meta-grid">
                <div>
                    <label>Tyyppi</label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['type']); ?></div>
                </div>
                <div>
                    <label>Työmaa</label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['site']); ?></div>
                </div>
                <div>
                    <label>Sijainti / tarkenne</label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['site_detail']); ?></div>
                </div>
                <div>
                    <label>Tapahtuma-aika</label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['occurred_at']); ?></div>
                </div>
            </div>
        </div>

        <!-- Varsinainen käännöslomake -->
        <div class="sf-form-section sf-form-language-grid">
            <div class="sf-lang-column sf-lang-base">
                <h3>Pohjakieli (<?php echo htmlspecialchars(strtoupper($baseFlash['lang'] ?? 'FI')); ?>)</h3>
<div class="sf-field">
    <label>Sisäinen otsikko (pohja)</label>
    <div class="sf-meta-value">
        <?php echo htmlspecialchars($baseFlash['title']); ?>
    </div>
</div>
                <div class="sf-field">
                    <label>Näkyvä otsikko (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo htmlspecialchars($baseFlash['title_short'] ?: $baseFlash['title']); ?>
                    </div>
                </div>

                <div class="sf-field">
                    <label>Lyhyt kuvaus (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo nl2br(htmlspecialchars($baseFlash['summary'])); ?>
                    </div>
                </div>

                <div class="sf-field">
                    <label>Laaja kuvaus (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo nl2br(htmlspecialchars($baseFlash['description'])); ?>
                    </div>
                </div>
            </div>

            <div class="sf-lang-column sf-lang-target">
                <h3>Käännös: <?php echo htmlspecialchars($langLabel); ?></h3>
<div class="sf-field">
    <label>Sisäinen otsikko (käännös)</label>
    <input type="text" name="title"
           value="<?php echo htmlspecialchars($baseFlash['title']); ?>">
</div>
                <div class="sf-field">
                    <label>Näkyvä otsikko (käännös)</label>
                    <input type="text" name="title_short"
                           value="<?php echo htmlspecialchars($baseFlash['title_short'] ?: $baseFlash['title']); ?>">
                </div>

                <div class="sf-field">
                    <label>Lyhyt kuvaus (käännös)</label>
                    <textarea name="summary" rows="3"><?php echo htmlspecialchars($baseFlash['summary']); ?></textarea>
                </div>

                <div class="sf-field">
                    <label>Laaja kuvaus (käännös)</label>
                    <textarea name="description" rows="8"><?php echo htmlspecialchars($baseFlash['description']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="sf-form-actions">
            <a href="index.php?page=view&id=<?php echo (int)$baseFlash['id']; ?>" class="sf-btn sf-btn-secondary">
                Peruuta
            </a>
            <button type="submit" class="sf-btn sf-btn-primary">
                Tallenna kieliversio
            </button>
        </div>
    </form>
</div>
<!-- Kuvainfo JavaScript-datan muodossa -->
<script>
window.SF_BASE_URL = <?php echo json_encode($config['base_url'] ?? ''); ?>;

// Apufunktio kuva-URL:ien muodostamiseen
function getImageUrlForJs(filename) {
    if (! filename) return '';
    const base = window.SF_BASE_URL;
    
    // Tarkista uploads/images
    // (JS ei voi tarkistaa tiedostojärjestelmää, joten oletetaan)
    return base + '/uploads/images/' + filename;
}

// Kuvainfo preview-modalille
window.SF_FLASH_DATA = {
    id: <?php echo (int)$baseFlash['id']; ?>,
    type: <?php echo json_encode($baseFlash['type']); ?>,
    lang: <?php echo json_encode($baseFlash['lang'] ?? 'fi'); ?>,
    title: <?php echo json_encode($baseFlash['title']); ?>,
    title_short: <?php echo json_encode($baseFlash['title_short'] ?? $baseFlash['title']); ?>,
    summary: <?php echo json_encode($baseFlash['summary'] ?? ''); ?>,
    description: <?php echo json_encode($baseFlash['description'] ?? ''); ?>,
    site:  <?php echo json_encode($baseFlash['site'] ?? ''); ?>,
    site_detail: <?php echo json_encode($baseFlash['site_detail'] ?? ''); ?>,
    occurred_at: <?php echo json_encode($baseFlash['occurred_at'] ?? ''); ?>,
    
    // Kuvatiedostot
    image_main: <?php echo json_encode($baseFlash['image_main'] ?? ''); ?>,
    image_2: <?php echo json_encode($baseFlash['image_2'] ?? ''); ?>,
    image_3: <?php echo json_encode($baseFlash['image_3'] ?? ''); ?>,
    
    // Kuva-URLit
    image_main_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_main'] ??  ''); ?>),
    image_2_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_2'] ?? ''); ?>),
    image_3_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_3'] ?? ''); ?>),
    
    // Muunnokset ja grid-tyyli
    image1_transform: <?php echo json_encode($baseFlash['image1_transform'] ?? ''); ?>,
    image2_transform: <?php echo json_encode($baseFlash['image2_transform'] ?? ''); ?>,
    image3_transform: <?php echo json_encode($baseFlash['image3_transform'] ?? ''); ?>,
    grid_style: <?php echo json_encode($baseFlash['grid_style'] ?? 'grid-3-main-top'); ?>,
};

window.SF_SUPPORTED_LANGS = {
    'fi': { label: 'FI', icon: 'finnish-flag.png' },
    'sv': { label: 'SV', icon: 'swedish-flag.png' },
    'en': { label: 'EN', icon: 'english-flag.png' },
    'it': { label: 'IT', icon: 'italian-flag.png' },
    'el': { label: 'EL', icon: 'greece-flag.png' }
};
</script>