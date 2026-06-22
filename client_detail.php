<?php
require_once 'db.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);
$created = isset($_GET['created']);

// ── Suppression depuis cette page ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    $sr = $db->prepare("SELECT id_sous_reseau FROM CLIENT WHERE id_client = ?");
    $sr->execute([$del_id]);
    $row = $sr->fetch();
    if ($row && $row['id_sous_reseau']) {
        $db->prepare("UPDATE SOUS_RESEAU SET etat = 'libre' WHERE id_sous_reseau = ?")
           ->execute([$row['id_sous_reseau']]);
    }
    $db->prepare("DELETE FROM CLIENT WHERE id_client = ?")->execute([$del_id]);
    header('Location: index.php');
    exit;
}

// ── Récupération client ────────────────────────────────────
$stmt = $db->prepare("
    SELECT c.*, sr.adresse_reseau, sr.premiere_ip, sr.prefixe,
           r.nom AS routeur_nom, r.adresse_loopback,
           s.nom_site, s.groupe
    FROM CLIENT c
    LEFT JOIN SOUS_RESEAU sr    ON c.id_sous_reseau = sr.id_sous_reseau
    LEFT JOIN ROUTEUR_PE r      ON c.id_routeur_pe  = r.id_routeur_pe
    LEFT JOIN PLAGE_ADRESSES pa ON sr.id_plage       = pa.id_plage
    LEFT JOIN SITE s            ON pa.id_site         = s.id_site
    WHERE c.id_client = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    header('Location: index.php');
    exit;
}

$sites = $db->query("SELECT * FROM SITE ORDER BY id_site")->fetchAll();

// ── Génération config Cisco ────────────────────────────────
$as     = 65556;
$rd     = "$as:{$c['id_client']}";
$vrf    = $c['nom_vrf'];
$vlan   = $c['vlan'];
$pe     = $c['routeur_nom'] ?? 'PE';
$subnet = $c['adresse_reseau'] . '/' . $c['prefixe'];
$gw     = $c['premiere_ip'];

$config = <<<CFG
! ── Configuration générée pour {$c['nom']} ──────────────────
!
! Routeur PE : $pe
! Site       : {$c['nom_site']} (Groupe {$c['groupe']})
!
! ── 1. Définition de la VRF ─────────────────────────────────
ip vrf $vrf
 rd $rd
 route-target export $rd
 route-target import $rd
!
! ── 2. Sous-interface vers le client ────────────────────────
interface GigabitEthernet0/0.$vlan
 encapsulation dot1Q $vlan
 ip vrf forwarding $vrf
 ip address $gw 255.255.255.240
 no shutdown
!
! ── 3. MP-BGP (échange routes VPN entre PE) ─────────────────
router bgp $as
 !
 address-family vpnv4
  neighbor <IP_PE_VOISIN> activate
  neighbor <IP_PE_VOISIN> send-community extended
 exit-address-family
 !
 address-family ipv4 vrf $vrf
  redistribute connected
 exit-address-family
!
! ── 4. OSPF (connectivité interne P/PE) ─────────────────────
router ospf 1
 network <LOOPBACK_IP> 0.0.0.0 area 0
!
! Réseau attribué : $subnet
! Première IP     : $gw
! VLAN            : $vlan
! Route Dist.     : $rd
CFG;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($c['nom']) ?> — IPAM</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>

<header class="navbar">
    <a class="brand" href="index.php">
        <i class="bi bi-diagram-3-fill"></i> IPAM
    </a>
    <nav>
        <a href="index.php"><i class="bi bi-people-fill"></i> Clients</a>
        <?php foreach ($sites as $s): ?>
        <a href="site.php?id=<?= $s['id_site'] ?>">
            <i class="bi bi-building"></i> <?= htmlspecialchars($s['nom_site']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <a href="nouveau_client.php" class="btn-new">
        <i class="bi bi-plus-circle-fill"></i> Nouveau client
    </a>
</header>

<div class="container">

    <a href="index.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Retour à la liste
    </a>

    <?php if ($created): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        Client <strong><?= htmlspecialchars($c['nom']) ?></strong> créé avec succès !
        VLAN <strong><?= $c['vlan'] ?></strong> —
        Sous-réseau <strong><?= $c['adresse_reseau'] ?>/<?= $c['prefixe'] ?></strong>
    </div>
    <?php endif; ?>

    <!-- Infos client + réseau -->
    <div class="detail-grid">

        <div class="card">
            <div class="card-header blue">
                <span><i class="bi bi-person-fill"></i> Informations client</span>
            </div>
            <div class="card-body" style="padding:0">
                <table class="kv-table">
                    <tr><th>ID client</th>          <td><code><?= $c['id_client'] ?></code></td></tr>
                    <tr><th>Nom</th>                 <td><strong><?= htmlspecialchars($c['nom']) ?></strong></td></tr>
                    <tr><th>VLAN</th>                <td><span class="badge badge-info"><?= $c['vlan'] ?></span></td></tr>
                    <tr><th>VRF</th>                 <td><code><?= htmlspecialchars($c['nom_vrf']) ?></code></td></tr>
                    <tr><th>Route Distinguisher</th> <td><code>65556:<?= $c['id_client'] ?></code></td></tr>
                    <tr><th>Date création</th>        <td><?= $c['date_creation'] ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header green">
                <span><i class="bi bi-hdd-network-fill"></i> Informations réseau</span>
            </div>
            <div class="card-body" style="padding:0">
                <table class="kv-table">
                    <tr><th>Sous-réseau</th>         <td><code><?= $c['adresse_reseau'] ?>/<?= $c['prefixe'] ?></code></td></tr>
                    <tr><th>Première IP (passerelle)</th><td><code><?= $c['premiere_ip'] ?></code></td></tr>
                    <tr><th>Masque</th>               <td><code>255.255.255.240</code></td></tr>
                    <tr><th>Routeur PE</th>            <td><?= htmlspecialchars($c['routeur_nom'] ?? '—') ?></td></tr>
                    <tr><th>Loopback PE</th>           <td><code><?= htmlspecialchars($c['adresse_loopback'] ?? '—') ?></code></td></tr>
                    <tr><th>Site</th>                  <td><?= htmlspecialchars($c['nom_site']) ?> (Groupe <?= $c['groupe'] ?>)</td></tr>
                </table>
            </div>
        </div>

    </div>

    <!-- Config Cisco -->
    <div class="card">
        <div class="card-header dark">
            <span><i class="bi bi-terminal-fill"></i> Configuration Cisco IOS générée</span>
            <button class="btn btn-outline" id="copy-btn"
                    onclick="copyConfig()"
                    style="color:#fff;border-color:rgba(255,255,255,.4);font-size:.82rem;padding:.3rem .8rem">
                <i class="bi bi-clipboard"></i> Copier
            </button>
        </div>
        <pre class="config" id="cfg"><?= htmlspecialchars($config) ?></pre>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:.75rem;margin-top:1.5rem">
        <a href="index.php" class="btn btn-outline">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
        <form method="post" onsubmit="return confirm('Supprimer définitivement ce client ?')">
            <input type="hidden" name="delete_id" value="<?= $c['id_client'] ?>">
            <button class="btn btn-danger" type="submit">
                <i class="bi bi-trash"></i> Supprimer ce client
            </button>
        </form>
        <?php if ($created): ?>
        <a href="nouveau_client.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Ajouter un autre
        </a>
        <?php endif; ?>
    </div>

</div>

<div id="toast"></div>
<script src="main.js"></script>
<script>
function copyConfig() {
    const text = document.getElementById('cfg').innerText;
    navigator.clipboard.writeText(text).then(() => {
        document.getElementById('copy-btn').innerHTML = '<i class="bi bi-clipboard-check"></i> Copié';
        showToast('Configuration copiée !');
        setTimeout(() => {
            document.getElementById('copy-btn').innerHTML = '<i class="bi bi-clipboard"></i> Copier';
        }, 2000);
    });
}
</script>
</body>
</html>
