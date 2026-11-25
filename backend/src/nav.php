<?php
declare(strict_types=1);
// Shared sidebar navigation for the inventory app.
// Include after bootstrap + itsm-auth. Expects $actor, $customers, $selectedCustomer to be set.
function invNav(array $actor, array $customers, int $selectedCustomer, string $active = ''): void {
    $links = [
        ['dashboard.php', 'Dashboard', 'dashboard'],
        ['v3.php', 'Enheder', 'devices'],
        ['v2.php', 'Historik', 'history'],
        ['software.php', 'Software', 'software'],
        ['enrollments.php', 'Registrering', 'enrollments'],
        ['downloads.php', 'Downloads', 'downloads'],
    ];
    if ($actor['admin']) {
        $links[] = ['v3.php?view=settings', 'Indstillinger', 'settings'];
        $links[] = ['v3.php?view=integrations', 'Integrationer', 'integrations'];
    }
    echo '<aside class="inv-sidebar">';
    echo '<div class="inv-sidebar-brand"><a href="./" class="inv-brand">PC INVENTORY</a><span class="inv-brand-sub">AGENT</span></div>';
    echo '<nav class="inv-nav" aria-label="Hovedmenu">';
    foreach ($links as [$href, $label, $key]) {
        $cls = $active === $key ? 'active' : '';
        echo '<a href="' . htmlspecialchars($href) . '" class="inv-nav-link ' . $cls . '">';
        echo htmlspecialchars($label);
        echo '</a>';
    }
    echo '</nav>';
    if (count($customers) > 1) {
        echo '<form method="get" class="inv-customer-select">';
        echo '<label>Kunde<select name="customer" onchange="this.form.submit()">';
        echo '<option value="">Alle kunder</option>';
        foreach ($customers as $c) {
            $sel = ((int)$c['id'] === $selectedCustomer) ? 'selected' : '';
            echo '<option value="' . (int)$c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['name']) . '</option>';
        }
        echo '</select></label>';
        foreach ($_GET as $k => $v) {
            if ($k === 'customer') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
        }
        echo '</form>';
    } elseif (count($customers) === 1) {
        echo '<div class="inv-customer-single">' . htmlspecialchars($customers[0]['name']) . '</div>';
    }
    echo '<a href="/" class="inv-back">← ITSM</a>';
    echo '</aside>';
    echo '<div class="inv-main">';
}
