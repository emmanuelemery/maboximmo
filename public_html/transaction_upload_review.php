<?php
/**
 * transaction_upload_review.php
 *
 * Sprint 6 · A1 — Convergence pipeline documentaire (dev only).
 * Cette page est désormais un WRAPPER de rétrocompatibilité.
 * Toutes les URLs legacy continuent de fonctionner et sont redirigées
 * vers doc_upload_review.php?source=transaction (page unifiée).
 *
 * Ancien comportement : page de revue 1-clic MVP Transaction E2E V0.
 * Voir doc_upload_review.php pour la nouvelle implémentation paramétrable.
 */

declare(strict_types=1);

$qs = $_GET;
$qs['source']   = 'transaction';
$qs['ctx_type'] = $qs['ctx_type'] ?? 'BIEN';

$target = 'doc_upload_review.php?' . http_build_query($qs);
header('Location: ' . $target, true, 302);
exit;
