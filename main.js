/* ── Toast ────────────────────────────────────────────────── */
function showToast(msg) {
    // Récupère l'élément toast dans le DOM
    const toast = document.getElementById('toast');
    // Si l'élément n'existe pas sur cette page, on arrête
    if (!toast) return;
    // Injecte l'icône Bootstrap + le message
    toast.innerHTML = '<i class="bi bi-check-circle"></i> ' + msg;
    // Rend le toast visible via la classe CSS 'show'
    toast.classList.add('show');
    // Masque le toast après 2,5 secondes
    setTimeout(() => toast.classList.remove('show'), 2500);
}

/* ── Auto-dismiss alertes succès après 4s ─────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    // Attend que le DOM soit fully chargé avant de chercher les alertes
    document.querySelectorAll('.alert-success').forEach(el => {
        setTimeout(() => {
            // Démarre un fondu sortant en 0,5s
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            // Supprime l'élément du DOM une fois le fondu terminé
            setTimeout(() => el.remove(), 500);
        }, 4000); // Attend 4s avant de déclencher le fondu
    });
});