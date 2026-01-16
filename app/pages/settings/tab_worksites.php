<?php
// app/pages/settings/tab_worksites.php
declare(strict_types=1);

// Hae työmaat
$worksites = [];
$worksitesRes = $mysqli->query('SELECT id, name, is_active FROM sf_worksites ORDER BY name ASC');
if ($worksitesRes) {
    while ($w = $worksitesRes->fetch_assoc()) {
        $worksites[] = $w;
    }
    $worksitesRes->free();
}
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(
        sf_term('settings_worksites_heading', $currentUiLang) ?? 'Työmaiden hallinta',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</h2>

<form
    method="post"
    class="sf-form-inline"
action="app/actions/worksites_save.php"
    data-sf-ajax="1"
>
    <input type="hidden" name="form_action" value="add">
    <?= sf_csrf_field() ?>
    <label for="ws-name">
        <?= htmlspecialchars(
            sf_term('settings_worksites_add_label', $currentUiLang) ?? 'Uusi työmaa:',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </label>
    <input type="text" id="ws-name" name="name" required>
    <button type="submit">
        <?= htmlspecialchars(
            sf_term('btn_add', $currentUiLang) ?? 'Lisää',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </button>
</form>

<?php if (empty($worksites)): ?>
    <p class="sf-notice sf-notice-info">
        <?= htmlspecialchars(
            sf_term('settings_worksites_empty', $currentUiLang) ?? 'Ei työmaita. Lisää ensimmäinen työmaa yllä olevalla lomakkeella.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
<?php else: ?>
<table class="sf-table sf-table-worksites">
    <thead>
        <tr>
            <th>ID</th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_active', $currentUiLang) ?? 'Aktiivinen',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_actions', $currentUiLang) ?? 'Toiminnot',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($worksites as $ws): ?>
            <tr class="<?= ((int)$ws['is_active'] === 1) ? '' : 'is-inactive' ?>">
                <td><?= (int)$ws['id'] ?></td>
                <td><?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
    <?= ((int)$ws['is_active'] === 1)
        ? htmlspecialchars(sf_term('common_yes', $currentUiLang) ?? 'Kyllä', ENT_QUOTES, 'UTF-8')
        : htmlspecialchars(sf_term('common_no', $currentUiLang) ?? 'Ei', ENT_QUOTES, 'UTF-8') ?>
</td>
                <td>
                    <form
                        method="post"
                        class="sf-inline-form"
                        action="app/actions/worksites_save.php"
                        data-sf-ajax="1"
                    >
                        <input type="hidden" name="form_action" value="toggle">
                        <?= sf_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <button type="submit">
                            <?php
                            if ((int)$ws['is_active'] === 1) {
                                echo htmlspecialchars(
                                    sf_term('settings_worksites_action_disable', $currentUiLang) ?? 'Passivoi',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            } else {
                                echo htmlspecialchars(
                                    sf_term('settings_worksites_action_enable', $currentUiLang) ?? 'Aktivoi',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            }
                            ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>