<?php
// inc/p_portefeuille_view.php — Rendu HTML de la page publique p.php (design Régie EMERY).
// Variables attendues : $e, $token, $envoi, $header, $biensJs, $destNom, $destInit, $typeDest,
// $pxMin,$pxMax,$sfMin,$sfMax,$nbBiens, $needConsent, $joursRestants, $fmtK.
// Branding dynamique (résolu dans p.php) avec repli Régie EMERY.
$brand = $brand ?? ['nom' => 'Régie EMERY', 'logo' => app_url('/images/logos/regie-emery.jpg'),
                    'tagline' => 'Location – Gestion – Syndic – Transaction', 'email' => 'contact@regie-emery.com', 'ville' => 'Lyon'];
$brandNom = (string)$brand['nom'];
$logo = (string)$brand['logo'];
$titreCrit = $typeDest === 'investisseur' ? '📈 Investisseur' : ($typeDest === 'commercialisateur' ? '🏷️ Commercialisateur' : '—');
$budget = ($fmtK($pxMin) || $fmtK($pxMax)) ? trim(($fmtK($pxMin) ?: '') . ' – ' . ($fmtK($pxMax) ?: '')) : null;
$surfTxt = ($sfMin || $sfMax) ? (($sfMin ? (int)$sfMin : '') . ' – ' . ($sfMax ? (int)$sfMax : '') . ' m²') : null;
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?= $e($envoi['sujet'] ?: 'Votre portefeuille') ?> · <?= $e($brandNom) ?></title>
<style>
:root{--bleu:#1f5fc0;--bleu-d:#143c80;--marine:#10254d;--vert:#3fb39a;--vert-d:#2c8f7c;--violet:#9c2d8a;
--ink:#15233b;--soft:#5f6b7e;--faint:#9aa4b4;--line:#e8ecf3;--bg:#f6f8fc;
--sh-s:0 1px 2px rgba(16,37,77,.04),0 8px 30px rgba(16,37,77,.07);--sh-m:0 10px 40px rgba(16,37,77,.12);}
*{box-sizing:border-box;margin:0;padding:0}html{scroll-behavior:smooth}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.5}
img{max-width:100%;display:block}a{color:inherit;text-decoration:none}
.wrap{max-width:1140px;margin:0 auto;padding:0 24px}
.nav{position:sticky;top:0;z-index:50;backdrop-filter:saturate(180%) blur(20px);background:rgba(255,255,255,.82);border-bottom:1px solid var(--line)}
.nav .wrap{display:flex;align-items:center;gap:22px;height:104px}
.nav .logo{height:84px;flex:none}
.nav .agent{display:flex;align-items:center;gap:12px;padding-left:22px;border-left:1px solid var(--line)}
.nav .agent .pp{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;font-size:18px;background:linear-gradient(135deg,var(--bleu),var(--violet));box-shadow:var(--sh-s)}
.nav .agent .role{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--vert-d)}
.nav .agent .nm{font-size:15px;font-weight:800;margin:1px 0}
.nav .agent .fn{font-size:11.5px;color:var(--soft);font-weight:600;margin-bottom:2px}
.coord-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;box-shadow:var(--sh-s)}
.coord-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.coord-sub{display:flex;gap:16px;flex-wrap:wrap;color:var(--soft);font-size:13.5px;margin-top:4px}
.coord-edit{cursor:pointer;border:1px solid var(--bleu);background:#fff;color:var(--bleu);border-radius:10px;padding:9px 14px;font-weight:700;font-size:13px}
.coord-edit:hover{background:var(--bleu);color:#fff}
.coord-form{display:none;margin-top:16px;border-top:1px solid var(--line);padding-top:16px}
.coord-form.on{display:block}
.coord-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.coord-grid label{display:flex;flex-direction:column;font-size:11.5px;font-weight:700;color:var(--soft);gap:4px}
.coord-grid input{border:1px solid var(--line);border-radius:9px;padding:9px 11px;font-size:14px;color:var(--ink)}
.coord-grid input:focus{outline:none;border-color:var(--bleu);box-shadow:0 0 0 2px rgba(31,95,192,.12)}
.coord-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:14px}
.coord-msg{margin-right:auto;font-size:13px}
.coord-cancel{cursor:pointer;border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 14px;font-weight:700;font-size:13px;color:var(--soft)}
.coord-save{cursor:pointer;border:none;background:var(--bleu);color:#fff;border-radius:10px;padding:9px 16px;font-weight:800;font-size:13px}
@media(max-width:600px){.coord-grid{grid-template-columns:1fr}}
.nav .agent .co{font-size:12.5px;color:var(--soft);display:flex;gap:12px;flex-wrap:wrap}
.nav .sp{flex:1}
.nav .pill{display:flex;align-items:center;gap:10px;background:#e6f6f1;border:1px solid #c5e8dd;padding:8px 14px;border-radius:14px}
.nav .pill .t1{font-size:12px;font-weight:800;color:var(--vert-d);line-height:1.1}
.nav .pill .t2{font-size:11.5px;font-weight:700;color:var(--soft);margin-top:2px}
.nav .pill .t2 b{color:var(--ink)}
.nav .pill.warn{background:#fdeee4;border-color:#f3cda9}.nav .pill.warn .t1{color:#b86a1f}
@media(max-width:860px){.nav .wrap{height:auto;flex-wrap:wrap;padding:12px 24px;gap:14px}.nav .agent{border-left:none;padding-left:0}}
.pfselect{background:#fff;border-bottom:1px solid var(--line)}
.pfselect .wrap{display:flex;align-items:center;gap:10px;padding:12px 24px;overflow-x:auto}
.pfselect .lbl{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);white-space:nowrap;flex:none}
.pfselect a{flex:none;display:flex;flex-direction:column;gap:1px;padding:9px 16px;border-radius:12px;border:1px solid var(--line);background:var(--bg);white-space:nowrap}
.pfselect a .nm{font-size:13.5px;font-weight:800;color:var(--ink)}
.pfselect a .mt{font-size:11px;color:var(--soft)}
.pfselect a.cur{background:linear-gradient(135deg,var(--bleu),var(--bleu-d));border-color:transparent}
.pfselect a.cur .nm,.pfselect a.cur .mt{color:#fff}.pfselect a.cur .mt{opacity:.85}
.hero{position:relative;overflow:hidden;padding:64px 0 54px;background:radial-gradient(1200px 500px at 85% -10%,rgba(63,179,154,.16),transparent 60%),radial-gradient(900px 500px at 0% 110%,rgba(156,45,138,.10),transparent 55%),linear-gradient(180deg,#fff,var(--bg))}
.hero .row{display:flex;align-items:flex-start;justify-content:space-between;gap:40px}
.hero .main{flex:1;min-width:0}
.eyebrow{font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--bleu)}
.hero h1{font-size:clamp(30px,4.5vw,52px);font-weight:800;letter-spacing:-.025em;line-height:1.05;margin:14px 0 16px;background:linear-gradient(120deg,var(--marine),var(--bleu) 55%,var(--vert-d));-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{font-size:18px;color:var(--soft);max-width:620px}
.hero .who{display:flex;align-items:center;gap:14px;margin-top:26px}
.hero .who .av{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;font-size:18px;background:linear-gradient(135deg,var(--bleu),var(--violet));box-shadow:var(--sh-s)}
.hero .who b{font-size:15px;display:block}.hero .who span{font-size:13px;color:var(--soft)}
.countcard{flex:none;margin-top:6px;background:linear-gradient(140deg,var(--bleu),var(--violet));color:#fff;border-radius:22px;padding:22px 26px 20px;text-align:center;box-shadow:0 14px 34px rgba(31,95,192,.32)}
.countcard .k{font-size:11px;text-transform:uppercase;letter-spacing:.1em;font-weight:800;color:#d6e4fb}
.countcard .v{font-size:58px;font-weight:800;line-height:1;letter-spacing:-.03em;margin:6px 0 2px}
.countcard .s{font-size:12.5px;color:#e7eefb}
section{padding:48px 0}
.h2{font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--vert-d)}
.h3{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:8px 0 6px}
.sub{color:var(--soft);font-size:15px;max-width:640px}
.crit-card{margin-top:24px;background:linear-gradient(135deg,var(--marine),#1b3e7a);color:#fff;border-radius:22px;padding:28px 30px;box-shadow:var(--sh-m)}
.crit-card .lbl{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#9fc0ec;font-weight:700}
.crit-grid{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px}
.chip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:14px;padding:14px 18px;min-width:140px}
.chip .ck{font-size:11.5px;color:#a8c4e8;font-weight:700;text-transform:uppercase}
.chip .cv{font-size:19px;font-weight:800;margin-top:3px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;margin-top:28px}
.card{background:#fff;border:1px solid var(--line);border-radius:22px;overflow:hidden;box-shadow:var(--sh-s);transition:transform .25s,box-shadow .25s;cursor:pointer}
.card:hover{transform:translateY(-5px);box-shadow:var(--sh-m)}
.card .ph{height:188px;position:relative;display:grid;place-items:center;background-size:cover;background-position:center}
.card .ph .glyph{font-size:54px;opacity:.5}
.card .ph .tag{position:absolute;left:14px;top:14px;background:rgba(255,255,255,.92);font-size:11.5px;font-weight:800;color:var(--bleu-d);padding:6px 12px;border-radius:99px;box-shadow:var(--sh-s)}
.card .ph .rdt{position:absolute;right:14px;top:14px;background:var(--vert);color:#fff;font-size:12.5px;font-weight:800;padding:6px 12px;border-radius:99px}
.card .ph .nb{position:absolute;right:14px;bottom:14px;background:rgba(16,37,77,.75);color:#fff;font-size:11.5px;font-weight:700;padding:5px 11px;border-radius:99px}
.gv-btn{position:absolute;left:14px;bottom:14px;background:linear-gradient(135deg,#243B5C,#1a2c45);color:#fff;border:none;font-size:11.5px;font-weight:800;padding:6px 12px;border-radius:99px;cursor:pointer;box-shadow:0 4px 12px rgba(36,59,92,.35);display:inline-flex;align-items:center;gap:5px;transition:transform .12s}
.gv-btn:hover{transform:translateY(-1px)}
.gv-main-btn{position:absolute;right:14px;top:14px;z-index:3;background:linear-gradient(135deg,#243B5C,#1a2c45);color:#fff;border:none;font-size:12.5px;font-weight:800;padding:9px 16px;border-radius:12px;cursor:pointer;box-shadow:0 6px 18px rgba(36,59,92,.4);display:inline-flex;align-items:center;gap:7px;transition:transform .12s}
.gv-main-btn:hover{transform:translateY(-2px)}
.card .body{padding:18px 20px 20px}
.card .adr{font-size:18px;font-weight:800}.card .loc{font-size:13.5px;color:var(--soft);margin-top:2px}
.card .meta{display:flex;gap:8px;flex-wrap:wrap;margin:13px 0 0}
.card .meta span{font-size:12.5px;font-weight:700;color:var(--soft);background:var(--bg);border:1px solid var(--line);padding:5px 11px;border-radius:9px}
/* Card IMMEUBLE : pleine largeur, compacte, couleur immeuble (#3D7465). */
.card.card-imm{grid-column:1 / -1;display:flex;align-items:stretch;border-color:#cfe0da;background:linear-gradient(180deg,#f4faf8,#fff)}
.card.card-imm .ph{height:auto;min-height:132px;width:300px;flex:none;border-right:1px solid var(--line)}
.card.card-imm .ph .glyph{color:#3D7465;opacity:.55}
.card.card-imm .ph .tag{color:#fff;background:#3D7465;box-shadow:none}
.card.card-imm .body{flex:1;padding:16px 24px;display:flex;flex-direction:column;justify-content:center}
.card.card-imm .adr{color:#2f5c50}
.card.card-imm .meta span{color:#2f5c50;background:#eaf4f0;border-color:#cfe0da}
.card.card-imm .open{color:#3D7465}
@media(max-width:640px){.card.card-imm{flex-direction:column}.card.card-imm .ph{width:100%;height:150px;border-right:none;border-bottom:1px solid var(--line)}}
.rent{display:flex;gap:10px;margin:14px 0 4px}
.rent .r{flex:1;border:1px solid var(--line);border-radius:12px;padding:9px 12px}
.rent .r.bail{background:#eaf6f2;border-color:#bfe6da}.rent .r.enc{background:#f4eef7;border-color:#e3cfe9}
.rent .r .rk{font-size:10.5px;text-transform:uppercase;font-weight:800}
.rent .r.bail .rk{color:var(--vert-d)}.rent .r.enc .rk{color:var(--violet)}
.rent .r .rv{font-size:16px;font-weight:800;margin-top:1px}.rent .r .rv small{font-size:11px;color:var(--soft);font-weight:700}
.card .fin{display:flex;align-items:flex-end;justify-content:space-between;border-top:1px dashed var(--line);padding-top:15px;margin-top:14px}
.card .fin .px{font-size:24px;font-weight:800}.card .fin .px small{display:block;font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase}
.card .fin .open{font-size:13px;font-weight:800;color:var(--bleu);background:#eaf1fb;padding:9px 14px;border-radius:11px}
.card:hover .fin .open{background:var(--bleu);color:#fff}
.card.gone{cursor:default;opacity:.96}
.card.gone:hover{transform:none;box-shadow:var(--sh-s)}
.card.gone .ph{height:120px;background:repeating-linear-gradient(135deg,#eef1f6,#eef1f6 12px,#e6eaf1 12px,#e6eaf1 24px);filter:grayscale(1)}
.card.gone .body{padding:18px 20px 20px}
.gone-badge{display:inline-flex;align-items:center;gap:7px;background:#fbeae8;color:#c0453f;border:1px solid #f0c4bd;font-size:12.5px;font-weight:800;padding:7px 13px;border-radius:99px;margin-top:8px}
.promo{background:linear-gradient(180deg,#fff,var(--bg))}
.promo-logo{height:90px;margin-bottom:14px}
.pillars{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:26px}
.pillar{text-align:center;padding:24px 16px}
.pillar .ic{width:58px;height:58px;margin:0 auto 12px;border-radius:17px;display:grid;place-items:center;font-size:26px;background:#fff;border:1px solid var(--line);box-shadow:var(--sh-s)}
.pillar b{font-size:16px}.pillar p{font-size:13.5px;color:var(--soft);margin-top:5px}
footer{background:var(--marine);color:#aebfd8;padding:44px 0 38px;font-size:13px}
footer .conf{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px 22px;line-height:1.65;max-width:780px}
footer .meta{margin-top:20px;display:flex;gap:24px;flex-wrap:wrap;color:#7e90b0}footer b{color:#fff}
.modal{position:fixed;inset:0;z-index:100;background:rgba(16,37,77,.55);backdrop-filter:blur(6px);display:none;align-items:flex-start;justify-content:center;padding:34px 20px;overflow:auto}
.modal.on{display:flex}
.modal-x{position:fixed;top:18px;right:22px;z-index:120;width:46px;height:46px;border-radius:50%;border:none;cursor:pointer;background:#fff;color:var(--ink);font-size:21px;box-shadow:0 8px 24px rgba(0,0,0,.25);display:none;align-items:center;justify-content:center}
.modal.on .modal-x{display:flex}.modal-x:hover{background:var(--bleu);color:#fff}
.sheet{background:var(--bg);width:100%;max-width:920px;border-radius:26px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.4)}
.gallery{display:grid;grid-template-columns:1fr 1fr;gap:8px;height:300px;position:relative;background:#0b1b38}
.gallery .main{position:relative;overflow:hidden}
.gallery .main .pimg{width:100%;height:100%;display:grid;place-items:center;font-size:64px;color:rgba(255,255,255,.6);background-size:cover;background-position:center}
.gallery .thumbs{display:grid;grid-template-columns:1fr 1fr;grid-auto-rows:1fr;gap:8px}
.gallery .thumbs .t{position:relative;overflow:hidden;cursor:pointer;display:grid;place-items:center;font-size:26px;color:rgba(255,255,255,.55);background-size:cover;background-position:center}
.gallery .thumbs .t.more{background:rgba(16,37,77,.6)!important;color:#fff;font-size:15px;font-weight:800}
.gallery .cap{position:absolute;left:16px;bottom:14px;background:rgba(16,37,77,.72);color:#fff;font-size:12px;font-weight:700;padding:6px 12px;border-radius:99px}
.gallery .close{position:absolute;right:16px;top:16px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.92);display:grid;place-items:center;font-size:20px;cursor:pointer;border:none;z-index:3}
.gallery .rdt{position:absolute;left:16px;top:16px;background:var(--vert);color:#fff;font-weight:800;font-size:13px;padding:7px 14px;border-radius:99px;z-index:3}
.sheet .in{padding:26px 30px 32px}
.titlerow{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
.sheet h3{font-size:26px;font-weight:800;letter-spacing:-.02em}.sheet .loc2{color:var(--soft);margin-top:2px}
.pricetag{flex:none;text-align:right}.pricetag .pt-v{font-size:28px;font-weight:800;color:var(--bleu-d)}
.pricetag .pt-k{font-size:11.5px;font-weight:800;text-transform:uppercase;color:var(--faint);margin-top:1px}
.pricetag .pt-r{font-size:12px;font-weight:700;color:var(--vert-d);margin-top:5px}
.specs{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:22px 0}
.specs .s{background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 10px;text-align:center}
.specs .s .k{font-size:10.5px;text-transform:uppercase;color:var(--faint);font-weight:700}.specs .s .v{font-size:17px;font-weight:800;margin-top:3px}
.block{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px 22px;margin-bottom:16px;box-shadow:var(--sh-s)}
.block .bt{font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--bleu);margin-bottom:14px}
.rentbox{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.rentbox .rb{border-radius:14px;padding:16px 18px;position:relative}
.rentbox .rb.bail{background:#eaf6f2;border:1px solid #bfe6da}.rentbox .rb.enc{background:#f4eef7;border:1px solid #e3cfe9}
.rentbox .rb .k{font-size:11.5px;font-weight:800;text-transform:uppercase}.rentbox .rb.bail .k{color:var(--vert-d)}.rentbox .rb.enc .k{color:var(--violet)}
.rentbox .rb .v{font-size:24px;font-weight:800;margin-top:4px}.rentbox .rb .v small{font-size:13px;color:var(--soft);font-weight:700}
.rentbox .rb .n{font-size:12px;color:var(--soft);margin-top:4px}
.rb-rdt{position:absolute;top:12px;right:12px;text-align:center;background:#fff;border:1.5px solid var(--bleu);color:var(--bleu-d);border-radius:12px;padding:5px 11px;box-shadow:var(--sh-s)}
.rb-rdt b{font-size:16px;font-weight:800;display:block;line-height:1}.rb-rdt span{font-size:8.5px;font-weight:800;text-transform:uppercase;color:var(--soft)}
.encrow{display:flex;align-items:stretch;gap:12px;margin-top:14px;flex-wrap:wrap}
.encrow .encstatut{flex:1;min-width:240px;margin:0}.encrow .linkbtn{flex:none}
.encstatut{display:flex;align-items:center;gap:9px;padding:11px 15px;border-radius:12px;font-size:13.5px;font-weight:700}
.encstatut.ok{background:#e6f6f1;border:1px solid #bfe6da;color:var(--vert-d)}
.encstatut.compl{background:#fdf6e3;border:1px solid #ecd9a0;color:#a8741d}
.encstatut.ko{background:#fbeae8;border:1px solid #f0c4bd;color:#c0453f}
.rdtcompare{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
.rdtcompare .rc{border:1px solid var(--line);border-radius:12px;padding:12px 15px}
.rdtcompare .rc .k{font-size:11px;font-weight:800;text-transform:uppercase;color:var(--faint)}.rdtcompare .rc .v{font-size:22px;font-weight:800;margin-top:2px}
.rdtcompare .rc.act{background:var(--bg)}.rdtcompare .rc.act .v{color:var(--soft)}
.rdtcompare .rc.per{background:#eef4fc;border-color:#cfe0f5}.rdtcompare .rc.per .v{color:var(--bleu-d)}
.rdtcompare .rc .n{font-size:11px;color:var(--soft);margin-top:3px}
.descr{font-size:14.5px;color:#33415a;line-height:1.7}
.kv{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 26px;margin-top:16px}
.kv .row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding:8px 0;font-size:13.5px}
.kv .row span{color:var(--soft)}
.comment{background:#fffaf0;border:1px solid #f0e2c0;border-left:4px solid #d8a93a;border-radius:0 14px 14px 0;padding:16px 18px;font-size:14px;color:#6b541d;line-height:1.6}
.linkbtn{display:inline-flex;align-items:center;gap:9px;font-size:14px;font-weight:800;color:var(--bleu);background:#eaf1fb;border:1px solid #cfe0f5;padding:13px 18px;border-radius:13px}
.linkbtn:hover{background:var(--bleu);color:#fff}
.finhead{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;padding:4px 2px 16px;border-bottom:1px solid var(--line);margin-bottom:8px}
.finhead .fh-k{font-size:12px;font-weight:800;text-transform:uppercase;color:var(--faint)}
.finhead .fh-v{font-size:36px;font-weight:800;color:var(--bleu-d);line-height:1.05;margin-top:3px}
.finhead .fh-sub{font-size:13px;font-weight:700;color:var(--soft);margin-top:5px}.finhead .fh-sub b{color:var(--ink)}
.fintable{width:100%;border-collapse:collapse}
.fintable td{padding:11px 0;border-bottom:1px solid var(--line);font-size:14.5px}
.fintable td:last-child{text-align:right;font-weight:800}.fintable tr:last-child td{border:none}
.sim-out{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.sim-out .so{background:var(--bg);border:1px solid var(--line);border-radius:13px;padding:13px 12px;text-align:center}
.sim-out .so.hl{background:#eef4fc;border-color:#cfe0f5}
.sim-out .so .k{font-size:10px;font-weight:800;text-transform:uppercase;color:var(--faint)}.sim-out .so .v{font-size:19px;font-weight:800;margin-top:3px}.sim-out .so.hl .v{color:var(--bleu-d)}.sim-out .so .n{font-size:10px;color:var(--soft);margin-top:2px}
.docs{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.doc{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:18px 12px 14px;border:1px solid var(--line);border-radius:15px;background:var(--bg)}
.doc:hover{border-color:var(--bleu);background:#eef4fc;transform:translateY(-2px)}
.doc .di{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;font-size:21px;color:#fff}
.doc.dpe .di{background:linear-gradient(135deg,#3fb39a,#2c8f7c)}.doc.diag .di{background:linear-gradient(135deg,#3a6fb0,#1f5fc0)}.doc.bail .di{background:linear-gradient(135deg,#9c2d8a,#742069)}
.doc b{font-size:15px}.doc span{font-size:12px;color:var(--soft)}.doc .dl{font-size:12.5px;font-weight:800;color:var(--bleu);margin-top:2px}
.lock,.chatnote{font-size:11.5px;color:var(--faint);margin-top:12px}
.soonbox{display:flex;gap:14px;align-items:center;background:#f7f5f0;border:1px dashed #d9d2c4;border-radius:14px;padding:16px 18px}
.soonbox .soon-ic{font-size:28px;line-height:1}.soonbox b{font-size:14px;color:#5a5a55}
.simmodal{position:fixed;inset:0;z-index:300;background:rgba(16,37,77,.6);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;padding:20px}
.simmodal.on{display:flex}
.simcard{background:#fff;width:100%;max-width:440px;max-height:92vh;overflow:auto;border-radius:20px;box-shadow:0 30px 90px rgba(0,0,0,.4)}
.simhead{display:flex;justify-content:space-between;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #eee}
.simhead b{font-size:16px}.simsub{font-size:12px;color:var(--faint);margin-top:2px}
.simx{background:#f3f3f3;border:none;width:32px;height:32px;border-radius:50%;font-size:16px;cursor:pointer;color:#555}
.simbody{padding:18px 20px}
.simctrl{margin-bottom:16px}
.sl-top{display:flex;justify-content:space-between;font-size:13px;color:#5a5a55;margin-bottom:6px}.sl-top b{color:var(--bleu);font-size:14px}
.simctrl input[type=range]{width:100%;accent-color:var(--bleu)}
.sl-foot{display:flex;justify-content:space-between;font-size:10.5px;color:var(--faint);margin-top:2px}
.sim-res{background:#f7f5f0;border-radius:14px;padding:6px 14px;margin:14px 0}
.srr{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #ece7dd;font-size:13px;color:#5a5a55}
.srr:last-child{border-bottom:none}.srr b{font-size:15px;color:#2c2a28}
.srr.hl b{font-size:19px;color:var(--bleu)}
.simreset{width:100%;margin-top:6px;background:#fff;border:1px solid #d9d2c4;border-radius:10px;padding:9px;font-size:12.5px;color:#5a5a55;cursor:pointer}
.consent{position:fixed;inset:0;z-index:200;background:rgba(16,37,77,.72);backdrop-filter:blur(8px);display:<?= $needConsent ? 'flex' : 'none' ?>;align-items:center;justify-content:center;padding:28px 18px}
.consent .card{background:#fff;width:100%;max-width:720px;max-height:92vh;border-radius:24px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 90px rgba(0,0,0,.45)}
.consent .ch{padding:26px 32px 18px;border-bottom:1px solid var(--line);text-align:center}
.consent .ch img{height:70px;margin:0 auto 14px}.consent .ch h2{font-size:22px;font-weight:800}.consent .ch p{font-size:13.5px;color:var(--soft);margin-top:4px}
.consent .cbody{padding:22px 32px;overflow-y:auto;font-size:14px;line-height:1.65;color:#33415a}
.consent .cbody p{margin-bottom:12px}.consent .cbody ul{margin:0 0 12px;padding-left:20px}.consent .cbody li{margin-bottom:7px}
.consent .cfoot{padding:18px 32px 24px;border-top:1px solid var(--line);background:var(--bg)}
.consent .agree{display:flex;align-items:flex-start;gap:11px;font-size:13.5px;cursor:pointer}.consent .agree input{width:20px;height:20px;margin-top:1px;accent-color:var(--vert-d)}
.consent .cbtn{width:100%;margin-top:16px;border:none;cursor:pointer;background:var(--bleu);color:#fff;font-weight:800;font-size:15.5px;padding:15px;border-radius:14px}
.consent .cbtn:disabled{background:#bcccdf;cursor:not-allowed}
.consent .cnote{font-size:11.5px;color:var(--faint);text-align:center;margin-top:12px}
@media(max-width:860px){.hero .row{flex-direction:column;gap:24px}.countcard{align-self:flex-start}.pillars{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.specs{grid-template-columns:repeat(3,1fr)}.gallery{grid-template-columns:1fr;height:auto}.gallery .thumbs{grid-template-columns:repeat(4,1fr);height:80px}.kv,.rentbox{grid-template-columns:1fr}.docs{grid-template-columns:1fr}}
</style></head>
<body<?= $needConsent ? ' style="overflow:hidden"' : '' ?>>
<?php if (!empty($isPreview)): ?>
<div style="background:#1a237e;color:#fff;text-align:center;font-size:13px;font-weight:700;padding:9px 16px;">👁️ APERÇU INTERNE — vue telle que la verra le destinataire (les liens documents nécessitent un envoi réel). <span style="opacity:.8;font-weight:500;">Cette page n'est pas accessible au public.</span></div>
<?php endif; ?>

<?php if ($needConsent): ?>
<div class="consent" id="consent"><div class="card">
  <div class="ch"><img src="<?= $e($logo) ?>" alt="<?= $e($brandNom) ?>"><h2>Accès à votre portefeuille</h2><p>Merci de prendre connaissance des conditions ci-dessous avant d'accéder à votre sélection.</p></div>
  <div class="cbody">
    <p>Madame, Monsieur,</p>
    <p>Nous vous remercions de l'intérêt que vous portez aux opportunités immobilières qui vous sont présentées.</p>
    <p>Le portefeuille auquel vous accédez a été constitué spécifiquement à votre intention, en fonction des critères et informations que vous nous avez communiqués. Les biens, documents, données financières, estimations, analyses, diagnostics, photographies et informations associées sont communiqués à titre strictement confidentiel.</p>
    <p>En accédant à ce portefeuille, vous reconnaissez que&nbsp;:</p>
    <ul>
      <li>Les informations communiquées sont destinées à votre seule étude personnelle ou à celle de votre structure directement concernée par le projet d'acquisition ;</li>
      <li>Vous vous engagez à ne pas diffuser, reproduire, transférer ou communiquer tout ou partie des informations, documents ou accès qui vous sont transmis à des tiers sans l'accord préalable et écrit de notre société ;</li>
      <li>Les identifiants, liens d'accès et documents mis à votre disposition sont strictement personnels et ne peuvent être partagés ;</li>
      <li>Les informations contenues dans ce portefeuille ne constituent pas une offre ferme de vente et peuvent être modifiées, complétées, retirées ou mises à jour à tout moment ;</li>
      <li>La disponibilité des biens présentés ne peut être garantie tant qu'un engagement contractuel n'a pas été signé par les parties concernées.</li>
    </ul>
    <p>Toute utilisation, diffusion ou exploitation non autorisée des informations communiquées pourra entraîner la suppression immédiate des accès accordés et, le cas échéant, engager la responsabilité de son auteur.</p>
    <p>Nous restons à votre entière disposition pour tout complément d'information, visite ou étude d'une offre. Nous vous souhaitons une excellente consultation.</p>
  </div>
  <div class="cfoot">
    <form method="post" action="<?= $e(app_url('/p.php?t=' . $token)) ?>" id="consentForm">
      <input type="hidden" name="action" value="consent">
      <label class="agree"><input type="checkbox" id="agree" onchange="document.getElementById('cbtn').disabled=!this.checked"><span>Je reconnais avoir pris connaissance des conditions ci-dessus et j'en accepte les termes.</span></label>
      <button class="cbtn" id="cbtn" type="submit" disabled>Accéder à mon portefeuille →</button>
    </form>
    <div class="cnote">🔒 Votre acceptation est enregistrée (date, heure et adresse IP) à des fins de preuve.</div>
  </div>
</div></div>
<?php endif; ?>

<div class="nav"><div class="wrap">
  <img class="logo" src="<?= $e($logo) ?>" alt="<?= $e($brandNom) ?>">
  <div class="agent">
    <?php if (!empty($conseiller) && $conseiller['photo']): ?>
      <div class="pp" style="background-image:url('<?= $e($conseiller['photo']) ?>');background-size:cover;background-position:center"></div>
    <?php else: ?>
      <div class="pp"><?= $e(!empty($conseiller) && $conseiller['init'] ? $conseiller['init'] : 'RE') ?></div>
    <?php endif; ?>
    <div>
      <div class="role">Votre conseiller</div>
      <div class="nm"><?= $e(!empty($conseiller) && $conseiller['nom'] ? $conseiller['nom'] : $brandNom . ' · Transaction') ?></div>
      <?php if (!empty($conseiller) && $conseiller['fonction']): ?><div class="fn"><?= $e($conseiller['fonction']) ?> · <?= $e($brandNom) ?></div><?php endif; ?>
      <div class="co">
        <a href="mailto:<?= $e(!empty($conseiller) && $conseiller['email'] ? $conseiller['email'] : $brand['email']) ?>">✉️ <?= $e(!empty($conseiller) && $conseiller['email'] ? $conseiller['email'] : $brand['email']) ?></a>
        <?php if (!empty($conseiller) && $conseiller['tel']): ?><a href="tel:<?= $e(preg_replace('/\s+/', '', $conseiller['tel'])) ?>" style="margin-left:10px">📞 <?= $e($conseiller['tel']) ?></a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="sp"></div>
  <?php if ($joursRestants !== null): ?>
  <div class="pill<?= $joursRestants <= 7 ? ' warn' : '' ?>"><span style="font-size:17px">🔒</span><div>
    <div class="t1">Accès personnel & confidentiel</div>
    <div class="t2">Valable encore <b><?= (int)$joursRestants ?> jour<?= $joursRestants > 1 ? 's' : '' ?></b></div>
  </div></div>
  <?php endif; ?>
</div></div>

<?php if (count($portefeuilles) > 1): ?>
<div class="pfselect"><div class="wrap">
  <span class="lbl">Vos portefeuilles</span>
  <?php foreach ($portefeuilles as $p): ?>
    <a class="<?= $p['current'] ? 'cur' : '' ?>" href="<?= $e(app_url('/p.php?t=' . $p['token'])) ?>">
      <span class="nm"><?= $e($p['nom']) ?></span>
      <span class="mt"><?= (int)$p['nb'] ?> bien<?= $p['nb'] > 1 ? 's' : '' ?><?= $p['date'] ? ' · ' . $e(date('d/m/Y', strtotime($p['date']))) : '' ?></span>
    </a>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="hero"><div class="wrap"><div class="row">
  <div class="main">
    <div class="eyebrow"><?= $e($titreCrit) ?><?= $envoi['date_envoi'] ? ' · ' . $e(date('F Y', strtotime((string)$envoi['date_envoi']))) : '' ?></div>
    <h1>Une sélection<br>pensée pour vous.</h1>
    <p class="lead">Des opportunités immobilières choisies selon vos critères, avec leurs conditions financières, diagnostics et documents — réunies sur une page privée.</p>
    <?php if ($destNom): ?><div class="who"><div class="av"><?= $e($destInit) ?></div><div><b>Préparé pour <?= $e($destNom) ?></b><span>par <?= $e($brandNom) ?> · Transaction</span></div></div><?php endif; ?>
  </div>
  <?php $nbImmeubles = (int)($nbImmeubles ?? 0); ?>
  <div class="countcard">
    <?php if ($nbImmeubles > 0): ?>
      <div style="display:flex;gap:18px;align-items:flex-end;justify-content:center;">
        <div><div class="k">Immeuble<?= $nbImmeubles > 1 ? 's' : '' ?></div><div class="v"><?= $nbImmeubles ?></div></div>
        <div style="opacity:.4;font-size:26px;font-weight:300;">·</div>
        <div><div class="k">Bien<?= $nbBiens > 1 ? 's' : '' ?></div><div class="v"><?= (int)$nbBiens ?></div></div>
      </div>
      <div class="s">partagés pour vous</div>
    <?php else: ?>
      <div class="k">Biens</div><div class="v"><?= (int)$nbBiens ?></div><div class="s">sélectionnés pour vous</div>
    <?php endif; ?>
  </div>
</div></div></div>

<?php if (!$isPreview): ?>
<section><div class="wrap">
  <div class="coord-card">
    <div class="coord-head">
      <div>
        <div class="h3" style="margin:0">Vos coordonnées</div>
        <div class="coord-sub">
          <?php if ($destEmail): ?><span>✉️ <?= $e($destEmail) ?></span><?php endif; ?>
          <?php if ($destTel): ?><span>📞 <?= $e($destTel) ?></span><?php endif; ?>
          <?php if (!$destEmail && !$destTel): ?><span>Aucune coordonnée renseignée.</span><?php endif; ?>
        </div>
      </div>
      <button type="button" class="coord-edit" onclick="coordToggle()">✏️ Mettre à jour mes coordonnées</button>
    </div>
    <form id="coord-form" class="coord-form" onsubmit="return coordSave(event)">
      <div class="coord-grid">
        <label>Prénom<input name="prenom" value="<?= $e($destPrenom) ?>"></label>
        <label>Nom<input name="nom" value="<?= $e($destNomSeul) ?>"></label>
        <label>Email<input name="email" type="email" value="<?= $e($destEmail) ?>"></label>
        <label>Mobile<input name="mobile" value="<?= $e($destTel) ?>"></label>
        <label>Téléphone fixe<input name="telephone" value=""></label>
        <label>Adresse<input name="adresse_ligne1" value=""></label>
        <label>Code postal<input name="code_postal" value=""></label>
        <label>Ville<input name="ville" value=""></label>
      </div>
      <div class="coord-actions">
        <span id="coord-msg" class="coord-msg"></span>
        <button type="button" class="coord-cancel" onclick="coordToggle()">Annuler</button>
        <button type="submit" class="coord-save">Enregistrer mes coordonnées</button>
      </div>
    </form>
  </div>
</div></section>
<?php endif; ?>

<?php if ($titreCrit !== '—' || $budget || $surfTxt): ?>
<section><div class="wrap">
  <div class="h2">Votre cahier des charges</div>
  <div class="h3">Les critères que vous nous avez confiés</div>
  <div class="crit-card"><div class="lbl">Profil acquéreur</div><div class="crit-grid">
    <div class="chip"><div class="ck">Profil</div><div class="cv"><?= $e($titreCrit) ?></div></div>
    <?php if ($budget): ?><div class="chip"><div class="ck">Budget</div><div class="cv"><?= $e($budget) ?></div></div><?php endif; ?>
    <?php if ($surfTxt): ?><div class="chip"><div class="ck">Surface</div><div class="cv"><?= $e($surfTxt) ?></div></div><?php endif; ?>
  </div></div>
</div></section>
<?php endif; ?>

<section id="biens" style="padding-top:6px"><div class="wrap">
  <div class="h2">La sélection</div>
  <div class="h3"><?= (int)$nbBiens ?> bien<?= $nbBiens > 1 ? 's' : '' ?> à découvrir</div>
  <p class="sub">Cliquez sur un bien pour voir les photos, le descriptif, les loyers, les conditions financières et les documents.</p>
  <div class="grid" id="grid"></div>
</div></section>

<section class="promo"><div class="wrap">
  <img class="promo-logo" src="<?= $e($logo) ?>" alt="<?= $e($brandNom) ?>">
  <div class="h3">Un partenaire immobilier complet, à vos côtés</div>
  <p class="sub">Depuis Lyon, nous accompagnons propriétaires et investisseurs sur l'ensemble du cycle immobilier.</p>
  <div class="pillars">
    <div class="pillar"><div class="ic">🔑</div><b>Location</b><p>Recherche de locataires, baux, états des lieux.</p></div>
    <div class="pillar"><div class="ic">📊</div><b>Gestion</b><p>Gestion locative, comptes-rendus, optimisation.</p></div>
    <div class="pillar"><div class="ic">🏛️</div><b>Syndic</b><p>Administration de copropriété, transparente.</p></div>
    <div class="pillar"><div class="ic">🤝</div><b>Transaction</b><p>Vente, investissement, conseil patrimonial.</p></div>
  </div>
</div></section>

<footer><div class="wrap">
  <div class="conf"><b>Accès strictement personnel et confidentiel.</b> Ce portefeuille a été constitué spécifiquement à votre intention. Les informations, documents et données financières sont communiqués à titre confidentiel et ne constituent pas une offre ferme de vente. Le lien d'accès est personnel et ne peut être partagé ; il peut être mis à jour ou retiré à tout moment.</div>
  <div class="meta"><span><b><?= $e($brandNom) ?></b> · <?= $e($brand['tagline']) ?></span><span>📍 <?= $e($brand['ville']) ?></span><span>✉️ <?= $e($brand['email']) ?></span></div>
</div></footer>

<div class="modal" id="modal" onclick="if(event.target===this)closeBien()">
  <button class="modal-x" onclick="closeBien()">✕</button>
  <div class="sheet" id="sheet"></div>
</div>

<div class="simmodal" id="simmodal" onclick="if(event.target===this)closeSim()">
  <div class="simcard">
    <div class="simhead"><div><b>🎚️ Personnaliser ma simulation</b><div class="simsub" id="sim-adr"></div></div><button class="simx" onclick="closeSim()">✕</button></div>
    <div class="simbody">
      <div class="simctrl"><div class="sl-top"><span>Apport personnel</span><b id="sim-apport-v"></b></div><input type="range" id="sim-apport" min="0" max="40" step="1"><div class="sl-foot"><span>0 %</span><span id="sim-apport-pct"></span><span>40 %</span></div></div>
      <div class="simctrl"><div class="sl-top"><span>Durée du prêt</span><b id="sim-duree-v"></b></div><input type="range" id="sim-duree" min="5" max="25" step="1"><div class="sl-foot"><span>5 ans</span><span></span><span>25 ans</span></div></div>
      <div class="simctrl"><div class="sl-top"><span>Taux annuel</span><b id="sim-taux-v"></b></div><input type="range" id="sim-taux" min="1" max="6" step="0.05"><div class="sl-foot"><span>1 %</span><span></span><span>6 %</span></div></div>
      <div class="sim-res">
        <div class="srr"><span>Montant à financer</span><b id="sim-fin"></b></div>
        <div class="srr hl"><span>Mensualité</span><b id="sim-mens"></b></div>
        <div class="srr"><span>Effort d'épargne / mois</span><b id="sim-effort"></b></div>
        <div class="srr"><span>Soit, en % du loyer</span><b id="sim-pct"></b></div>
        <div class="srr"><span>Coût total des intérêts</span><b id="sim-int"></b></div>
      </div>
      <div class="chatnote">Loyer mensuel pris en compte : <b id="sim-loyer"></b>. Hypothèse de prêt amortissable à taux fixe, hors assurance emprunteur. Simulation indicative et non contractuelle.</div>
      <button type="button" class="simreset" onclick="resetSim()">↺ Revenir aux valeurs conseillées</button>
    </div>
  </div>
</div>

<script>
const BIENS = <?= json_encode($biensJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PF_TOKEN = <?= json_encode($isPreview ? '' : (string)($token ?? '')) ?>;
const PF_TRACK_URL = <?= json_encode(app_url('/api/portefeuille_track.php')) ?>;
function pfTrackBien(idb){
  if(!PF_TOKEN || !idb) return;
  const u = PF_TRACK_URL + '?t=' + encodeURIComponent(PF_TOKEN) + '&bien=' + idb;
  try { navigator.sendBeacon ? navigator.sendBeacon(u) : fetch(u,{keepalive:true}); } catch(e){}
}
const PALS=['linear-gradient(135deg,#3a6fb0,#1f5fc0)','linear-gradient(135deg,#3fb39a,#2c8f7c)','linear-gradient(135deg,#9c2d8a,#5e2b73)','linear-gradient(135deg,#5b86b0,#34507a)','linear-gradient(135deg,#e7b24a,#c8862b)','linear-gradient(135deg,#566a8c,#2c3e5c)'];
const parseEur=s=>parseFloat(String(s).replace(/[^\d]/g,''))||0;
const fmtEur=n=>Math.round(n).toLocaleString('fr-FR')+' €';
const pct=x=>(Math.round(x*10)/10).toString().replace('.',',')+' %';
const bg=(b,i)=>b.photos&&b.photos[i]?`background-image:url('${b.photos[i]}')`:`background:${PALS[i%PALS.length]}`;
function gv(i){ var b=BIENS[i]; if(b&&b.lat&&b.lng&&window.openGeoViews) openGeoViews(b.lat,b.lng,(b.adr||'')+(b.loc?(' · '+b.loc):'')); }
function gvCur(){ var b=window._curB; if(b&&b.lat&&b.lng&&window.openGeoViews) openGeoViews(b.lat,b.lng,(b.adr||'')+(b.loc?(' · '+b.loc):'')); }

const grid=document.getElementById('grid');
BIENS.forEach((b,i)=>{
  if(b.dispo===false){
    grid.insertAdjacentHTML('beforeend',`
      <div class="card gone">
        <div class="ph"><span class="glyph">🚫</span>${b.ref?`<span class="tag">${b.ref}</span>`:''}</div>
        <div class="body"><div class="adr" style="color:#9aa4b4">${b.adr||'Bien'}</div><div class="loc">${b.loc||''}</div>
          <div class="gone-badge">⚠️ Ce bien n'est plus disponible à la vente</div>
        </div></div>`);
    return;
  }
  if(b.is_immeuble){
    grid.insertAdjacentHTML('beforeend',`
     <div class="card card-imm" onclick="openBien(${i})">
       <div class="ph" style="${bg(b,0)}">${(b.photos&&b.photos.length)?'':'<span class="glyph">🏛️</span>'}
         <span class="tag">🏛️ ${b.ref||'IMMEUBLE'}</span>
         ${(b.photos&&b.photos.length)?`<span class="nb">📷 ${b.photos.length}</span>`:''}</div>
       <div class="body">
         <div class="adr">${b.adr}</div><div class="loc">${b.loc}</div>
         ${b.resume?`<div style="font-size:13px;color:#2f5c50;font-weight:600;margin-top:8px">${b.resume}</div>`:''}
         <div class="meta"><span>📂 ${(b.docs&&b.docs.length)||0} document(s) commun(s)</span>${(b.photos&&b.photos.length)?`<span>📷 ${b.photos.length} photo(s)</span>`:''}${(b.infos&&b.infos.length)?`<span>🏛️ ${b.infos.length} infos publiques</span>`:''}</div>
         <div class="fin" style="margin-top:12px"><div class="px"><small>Parties communes de l'immeuble</small></div><div class="open">Ouvrir l'immeuble →</div></div>
       </div></div>`);
    return;
  }
  const enc=b.loyerMax==='—'?`<div class="r enc"><div class="rk">Encadrement</div><div class="rv" style="font-size:13px">Non concerné</div></div>`
    :`<div class="r enc"><div class="rk">Plafond encadrt.</div><div class="rv">${b.loyerMax} <small>/mois</small></div></div>`;
  grid.insertAdjacentHTML('beforeend',`
   <div class="card" onclick="openBien(${i})">
     <div class="ph" style="${bg(b,0)}">${(b.photos&&b.photos.length)?'':'<span class="glyph">🏠</span>'}
       <span class="tag">${b.ref||''}</span>${b.rdt!=='—'?`<span class="rdt">${b.rdt} brut</span>`:''}
       ${(b.lat&&b.lng)?`<button type="button" class="gv-btn" onclick="event.stopPropagation();gv(${i})" title="Plan 2D · Street View · Vue 3D">🛰️ 3 vues</button>`:''}
       ${(b.photos&&b.photos.length)?`<span class="nb">📷 ${b.photos.length}</span>`:''}</div>
     <div class="body"><div class="adr">${b.adr}</div><div class="loc">${b.loc}</div>
       <div class="meta"><span>🏠 ${b.surf}</span>${b.type?`<span>🛏 ${b.type}</span>`:''}<span>${b.meuble?'🛋 Meublé':'📦 Vide'}</span><span>🔑 ${b.loyer!=='—'?'Loué':'Libre'}</span></div>
       <div class="rent"><div class="r bail"><div class="rk">Loyer (bail)</div><div class="rv">${b.loyer} <small>/mois</small></div></div>${enc}</div>
       <div class="fin"><div class="px"><small>Prix de vente FAI</small>${b.pv}</div><div class="open">Ouvrir le bien →</div></div>
     </div></div>`);
});

function photoSet(b){
  const n=b.photos&&b.photos.length?b.photos.length:0;
  const mainStyle=n?`background-image:url('${b.photos[0]}')`:`background:${PALS[0]}`;
  const gvOverlay=(b.lat&&b.lng)?`<button type="button" class="gv-main-btn" onclick="event.stopPropagation();gvCur()" title="Plan 2D · Street View · Vue 3D">🛰️ Voir en 3 vues</button>`:'';
  let main=`<div class="main"><div class="pimg" id="m-main" style="${mainStyle}">${n?'':'🏠'}</div>${gvOverlay}<div class="cap" id="m-cap">Photo 1${n?'/'+n:''}</div></div>`;
  let th='';
  const max=n?Math.min(n,5):4;
  for(let k=1;k<max;k++){
    const last=(k===4&&n>5);
    const stl=n&&b.photos[k]?`background-image:url('${b.photos[k]}')`:`background:${PALS[k%PALS.length]}`;
    th+=`<div class="t${last?' more':''}" style="${stl}" onclick="event.stopPropagation();swapPhoto(${k})">${last?('+'+(n-4)):(n?'':'🖼')}</div>`;
  }
  window._curB=b;
  return `${main}<div class="thumbs">${th}</div>`;
}
function swapPhoto(k){const b=window._curB;if(!b||!b.photos||!b.photos[k])return;
  document.getElementById('m-main').style.backgroundImage=`url('${b.photos[k]}')`;
  document.getElementById('m-main').textContent='';
  document.getElementById('m-cap').textContent=`Photo ${k+1}/${b.photos.length}`;}

function openBien(i){
  const b=BIENS[i];
  if(b&&b.idb)pfTrackBien(b.idb);
  // ── IMMEUBLE : détail = photos + TOUTES les infos publiques + documents communs (pas de finance/bail). ──
  if(b.is_immeuble){
    const dMap={dpe:['⚡','DPE'],diag:['🔬','Diagnostics'],bail:['📄','Bail'],tf:['💶','Taxe foncière'],carrez:['📐','Surface Carrez'],reglement:['🏛️','Règlement copro']};
    const docsI=(b.docs&&b.docs.length)?`<div class="docs">${b.docs.map(d=>{const m=dMap[d.kind]||['📄','Document'];return `<a class="doc ${d.kind}" href="${d.url}" target="_blank" rel="noopener"><div class="di">${m[0]}</div><div><b>${d.title||m[1]}</b><br><span>${(d.label||'').replace(/\.[a-z0-9]+$/i,'')} · PDF</span></div><span class="dl">Consulter →</span></a>`;}).join('')}</div><div class="lock">🔒 Documents sécurisés — liens personnels à durée limitée.</div>`:`<div style="color:#8a97a8;font-size:14px;padding:8px 2px">Aucun document communiqué.</div>`;
    const esc0=s=>(''+s).replace(/</g,'&lt;');
    const infosI=(b.infos&&b.infos.length)?`<div class="kv">${b.infos.map(x=>`<div class="row"><span>${esc0(x.k)}</span><b>${esc0(x.v)}</b></div>`).join('')}</div>`:`<div style="color:#8a97a8;font-size:14px">Aucune information publique renseignée pour cet immeuble.</div>`;
    // Détail complet par source (Cadastre & PLU, Altitude, Risques ERP, Copropriété registre national…).
    const sourcesI=(b.sources&&b.sources.length)?b.sources.map(s=>`<div class="block"><div class="bt">${esc0(s.icon||'🔎')} ${esc0(s.title||'Source')}</div><div class="kv">${(s.items||[]).map(it=>`<div class="row"><span>${esc0(it.k)}</span><b>${esc0(it.v)}</b></div>`).join('')}</div></div>`).join(''):'';
    document.getElementById('sheet').innerHTML=`
      <div class="gallery"><button class="close" onclick="closeBien()">✕</button>${photoSet(b)}</div>
      <div class="in">
        <div class="titlerow"><div><h3>🏛️ ${b.adr}</h3><div class="loc2">${b.loc||''}${(b.ref&&b.ref!=='IMMEUBLE')?' · '+b.ref:''}</div></div></div>
        <div class="block"><div class="bt">🏛️ Données publiques de l'immeuble</div>${infosI}</div>
        ${sourcesI}
        <div class="block"><div class="bt">📎 Documents communs</div>${docsI}</div>
      </div>`;
    document.getElementById('modal').classList.add('on');document.getElementById('modal').scrollTop=0;document.body.style.overflow='hidden';
    return;
  }
  const numFr=s=>parseFloat(String(s).replace(/[^\d.,]/g,'').replace(',','.'))||0;
  const loyN=numFr(b.loyer),maxN=b.loyerMax==='—'?0:numFr(b.loyerMax),netN=parseEur(b.nv);
  const rdtAct=netN?loyN*12/netN*100:0,rdtPer=(netN&&maxN)?maxN*12/netN*100:0;
  const rdtBadge=v=>v?`<div class="rb-rdt"><b>${pct(v)}</b><span>renta</span></div>`:'';
  const encBlock=b.loyerMax==='—'
    ?`<div class="rb enc"><div class="k">Encadrement des loyers</div><div class="v" style="font-size:18px">Non concerné</div><div class="n">Commune hors zone d'encadrement.</div></div>`
    :`<div class="rb enc">${rdtBadge(rdtPer)}<div class="k">Plafond d'encadrement</div><div class="v">${b.loyerMax} <small>/mois</small></div><div class="n">Loyer de référence majoré (indicatif).</div></div>`;
  let encBadge='',encCompare='';
  if(maxN){
    const st=b.encStatut||(loyN>maxN?'non_conforme':'conforme');
    if(st==='conforme')encBadge=`<div class="encstatut ok">✅ Loyer conforme à l'encadrement</div>`;
    else if(st==='complement')encBadge=`<div class="encstatut compl">🟡 Complément de loyer justifié (au bail).</div>`;
    else{encBadge=`<div class="encstatut ko">⚠️ Loyer supérieur au plafond — base retenue : le plafond (${b.loyerMax}).</div>`;
      encCompare=`<div class="rdtcompare"><div class="rc act"><div class="k">Renta actuelle</div><div class="v">${pct(rdtAct)}</div><div class="n">sur loyer du bail</div></div><div class="rc per"><div class="k">Renta possible</div><div class="v">${pct(rdtPer)}</div><div class="n">sur loyer encadré</div></div></div>`;}
  }
  const annonce=b.annonce?`<div class="block"><div class="bt">📣 Notre annonce</div><div class="comment">${b.annonce.replace(/</g,'&lt;')}</div></div>`:'';
  const dpv=parseEur(b.pv);
  // ── Simulation de financement (bases fixes : effort d'épargne ~10% du loyer → durée déduite) ──
  const sNet=parseEur(b.nv), sLoy=numFr(b.loyer);
  const sMontant=Math.round(dpv*1.078);          // montant total (prix FAI + frais notaire ~7,8%)
  const sApport=Math.round(sNet*0.10);           // apport perso = 10% du prix HORS honoraires (net vendeur)
  const sFinance=Math.max(0, sMontant-sApport);  // montant à financer
  const sTaux=parseFloat(String(b.simRate).replace(',','.'))||0;
  const sMens=Math.round(sLoy*1.10);             // mensualité = loyer + ~10% d'effort d'épargne
  const sEffort=Math.round(sLoy*0.10);
  let sDuree='—';
  if(sLoy>0 && sFinance>0){
    const r=sTaux/100/12;
    if(r===0) sDuree=(Math.round(sFinance/sMens/12*10)/10).toString().replace('.',',')+' ans';
    else if(sMens>sFinance*r){ const n=-Math.log(1-(sFinance*r)/sMens)/Math.log(1+r); sDuree=(Math.round(n/12*10)/10).toString().replace('.',',')+' ans'; }
  }
  window._simB={net:sNet,montant:sMontant,loyer:sLoy,taux:sTaux,usage:b.simUsage,adr:b.adr};
  const simBlock=`<div class="block"><div class="bt">📊 Simulation de financement</div>
    <div class="sim-out" style="grid-template-columns:repeat(3,1fr)">
      <div class="so"><div class="k">Montant total</div><div class="v">${fmtEur(sMontant)}</div><div class="n">prix FAI + frais notaire</div></div>
      <div class="so"><div class="k">Apport personnel</div><div class="v">${fmtEur(sApport)}</div><div class="n">10 % du prix hors hono.</div></div>
      <div class="so"><div class="k">Montant à financer</div><div class="v">${fmtEur(sFinance)}</div><div class="n">total − apport</div></div>
      <div class="so"><div class="k">Taux indicatif</div><div class="v">${b.simRate} %</div><div class="n">moyen régional (${b.simUsage})</div></div>
      <div class="so"><div class="k">Mensualité</div><div class="v">${sLoy>0?fmtEur(sMens):'—'}</div><div class="n">loyer + ~10 % d'effort</div></div>
      <div class="so hl"><div class="k">Durée estimée</div><div class="v">${sDuree}</div><div class="n">pour cet effort d'épargne</div></div>
    </div>
    ${sLoy>0?`<div class="chatnote">Base : effort d'épargne ≈ 10 % du loyer (mensualité ${fmtEur(sMens)} dont ${fmtEur(sEffort)} d'effort). Taux moyen régional ~ ${b.simRate} % (juin 2026), apport 10 % du prix hors honoraires. Simulation indicative et non contractuelle.</div>`
      :`<div class="chatnote">Loyer non renseigné : mensualité et durée non calculées. Montant total et apport restent indicatifs.</div>`}
    ${sLoy>0?`<div style="margin-top:12px"><button type="button" class="linkbtn" onclick="openSim()">🎚️ Personnaliser ma simulation</button> <span class="chatnote" style="margin-left:8px">Ajustez apport, durée et taux et voyez l'effet en direct.</span></div>`:''}
  </div>`;
  const docMap={dpe:['⚡','DPE','Performance énergétique'],diag:['🔬','Diagnostics','Dossier technique'],bail:['📄','Bail','Bail en cours'],tf:['💶','Taxe foncière','Avis d\'imposition'],carrez:['📐','Surface Carrez','Attestation de surface'],reglement:['🏛️','Règlement copro','Règlement de copropriété']};
  const docsInner=(b.docs&&b.docs.length)?`<div class="docs">${b.docs.map(d=>{const m=docMap[d.kind]||['📄','Document','Document'];return `<a class="doc ${d.kind}" href="${d.url}" target="_blank" rel="noopener"><div class="di">${m[0]}</div><div><b>${d.title||m[1]}</b><br><span>${(d.label||m[2]).replace(/\.[a-z0-9]+$/i,'')} · PDF</span></div><span class="dl">Consulter →</span></a>`;}).join('')}</div><div class="lock">🔒 Documents sécurisés — liens personnels à durée limitée. Seuls DPE, diagnostics et baux sont communiqués.</div>`:`<div style="color:#8a97a8;font-size:14px;padding:8px 2px;">Aucun document communiqué pour ce bien pour le moment.</div>`;
  const docsHtml=`<div class="block"><div class="bt">📎 Documents disponibles</div>${docsInner}</div>`;
  const encVerify=b.loyerMax!=='—'?`<div class="encrow">${encBadge}<a class="linkbtn" href="https://demarches.toodego.com/logement/encadrement-des-loyers-v2/" target="_blank" rel="noopener">🔎 Vérifier sur le simulateur officiel ↗</a></div>${encCompare}<div class="chatnote" style="margin-top:10px">À saisir dans le simulateur : <b>${b.adr}, ${b.loc}</b> · surface <b>${b.surf}</b>${b.type?` · <b>${b.type}</b>`:''} · <b>${b.meuble?'meublé':'vide'}</b>${b.annee!=='—'?` · construction <b>${b.annee}</b>`:''}.</div>`:encBadge;

  document.getElementById('sheet').innerHTML=`
    <div class="gallery"><button class="close" onclick="closeBien()">✕</button>${b.rdt!=='—'?`<span class="rdt">${b.rdt} brut</span>`:''}${photoSet(b)}</div>
    <div class="in">
      <div class="titlerow"><div><h3>${b.adr}</h3><div class="loc2">${b.loc}${b.ref?' · Réf. '+b.ref:''}</div></div>
        <div class="pricetag"><div class="pt-v">${b.pv}</div><div class="pt-k">Honoraires compris (FAI)</div>${b.rdt!=='—'?`<div class="pt-r">Rentabilité ${b.rdt} brut</div>`:''}</div></div>
      <div class="specs">
        <div class="s"><div class="k">Surface</div><div class="v">${b.surf}</div></div>
        <div class="s"><div class="k">Type</div><div class="v">${b.type||'—'}</div></div>
        <div class="s"><div class="k">Location</div><div class="v" style="font-size:15px">${b.meuble?'🛋 Meublé':'📦 Vide'}</div></div>
        <div class="s"><div class="k">Prix / m²</div><div class="v">${b.pm}</div></div>
        <div class="s"><div class="k">DPE</div><div class="v">${b.dpe}</div></div>
      </div>
      <div class="block"><div class="bt">🔑 Loyers</div>
        <div class="rentbox"><div class="rb bail">${rdtBadge(rdtAct)}<div class="k">Loyer actuel (bail)</div><div class="v">${b.loyer} <small>/mois</small></div><div class="n">Loyer perçu au bail en cours.</div></div>${encBlock}</div>
        ${encVerify}
      </div>
      ${b.bail?`<div class="block"><div class="bt">📋 Conditions du bail</div>
        <div class="kv">
          ${b.bail.nature?`<div class="row"><span>Nature</span><b>${b.bail.nature}</b></div>`:''}
          ${b.bail.charges?`<div class="row"><span>Charges</span><b>${b.bail.charges} /mois</b></div>`:''}
          ${b.bail.dg?`<div class="row"><span>Dépôt de garantie</span><b>${b.bail.dg}</b></div>`:''}
          ${b.bail.indice?`<div class="row"><span>Indice de révision</span><b>${b.bail.indice}</b></div>`:''}
          ${b.bail.effet?`<div class="row"><span>Prise d'effet</span><b>${b.bail.effet}</b></div>`:''}
          ${b.bail.fin?`<div class="row"><span>Échéance</span><b>${b.bail.fin}</b></div>`:''}
        </div></div>`:''}
      ${b.descr?`<div class="block"><div class="bt">🏠 Descriptif du bien</div><div class="descr">${b.descr.replace(/</g,'&lt;')}</div>
        <div class="kv"><div class="row"><span>Type</span><b>${b.type||'—'}</b></div><div class="row"><span>Surface</span><b>${b.surf}</b></div><div class="row"><span>Type de location</span><b>${b.meuble?'Meublé':'Vide (nu)'}</b></div><div class="row"><span>Chauffage</span><b>${b.chauf}</b></div><div class="row"><span>Année</span><b>${b.annee}</b></div><div class="row"><span>DPE</span><b>${b.dpe}</b></div></div></div>`:''}
      ${annonce}
      <div class="block"><div class="bt">💶 Conditions financières</div>
        <div class="finhead"><div><div class="fh-k">Prix de vente · honoraires inclus (FAI)</div><div class="fh-v">${b.pv}</div><div class="fh-sub">dont honoraires d'agence <b>${b.ho}</b> · charge acquéreur</div></div></div>
        <table class="fintable">
          <tr><td>Frais de notaire à prévoir <span style="color:var(--faint);font-weight:700">(~ 7,8 %)</span></td><td>${fmtEur(dpv*0.078)}</td></tr>
          <tr style="font-weight:800"><td>Budget total acquéreur estimé</td><td>${fmtEur(dpv*1.078)}</td></tr>
        </table>
        <div class="lock">ℹ️ Frais de notaire estimatifs (ancien), indicatifs ; le montant exact est arrêté par l'office notarial.</div>
      </div>
      ${simBlock}
      ${docsHtml}
      <div class="block soon"><div class="bt">💬 Échanger sur ce bien</div>
        <div class="soonbox"><div class="soon-ic">🚧</div><div><b>En cours de création</b><div class="chatnote" style="margin-top:4px">La messagerie privée avec votre conseiller pour ce bien sera bientôt disponible. En attendant, contactez <?= $e($brandNom) ?> directement.</div></div></div>
      </div>
    </div>`;
  document.getElementById('modal').classList.add('on');document.getElementById('modal').scrollTop=0;document.body.style.overflow='hidden';
}
function closeBien(){document.getElementById('modal').classList.remove('on');document.body.style.overflow='';}

// PF_TOKEN déjà déclaré plus haut (ligne ~408) — ne PAS le redéclarer (const en double = SyntaxError qui casse tout le script).
const PF_COORD_URL=<?= json_encode(app_url('/api/portefeuille_coordonnees.php')) ?>;
function coordToggle(){const f=document.getElementById('coord-form');if(f)f.classList.toggle('on');}
async function coordSave(ev){
  ev.preventDefault();
  const form=document.getElementById('coord-form');
  const msg=document.getElementById('coord-msg');
  const btn=form.querySelector('.coord-save');
  const fd=new FormData(form); fd.append('t',PF_TOKEN);
  btn.disabled=true; const old=btn.textContent; btn.textContent='…'; msg.textContent='';
  try{
    const r=await fetch(PF_COORD_URL,{method:'POST',body:fd});
    const j=await r.json();
    if(!j.success){msg.style.color='#c0392b';msg.textContent=j.message||'Erreur.';btn.disabled=false;btn.textContent=old;return false;}
    msg.style.color='#1e8a5a';msg.textContent='✅ '+(j.message||'Enregistré.');
    const d=j.data||{};
    const sub=document.querySelector('.coord-sub');
    if(sub){sub.innerHTML=(d.email?'<span>✉️ '+d.email+'</span>':'')+((d.mobile||d.telephone)?'<span>📞 '+(d.mobile||d.telephone)+'</span>':'');}
    setTimeout(()=>{form.classList.remove('on');btn.disabled=false;btn.textContent=old;},1400);
  }catch(e){msg.style.color='#c0392b';msg.textContent='Erreur réseau.';btn.disabled=false;btn.textContent=old;}
  return false;
}

function simDuree(){const s=window._simB;if(!s||!(s.loyer>0))return 20;const fin=Math.max(0,s.montant-Math.round(s.net*0.10));const r=s.taux/100/12,m=Math.round(s.loyer*1.10);if(fin<=0)return 20;if(r===0)return Math.min(25,Math.max(5,Math.round(fin/m/12)));if(m<=fin*r)return 25;const n=-Math.log(1-(fin*r)/m)/Math.log(1+r);return Math.min(25,Math.max(5,Math.round(n/12)));}
function openSim(){const s=window._simB;if(!s)return;document.getElementById('sim-adr').textContent=s.adr||'';document.getElementById('sim-loyer').textContent=fmtEur(s.loyer);resetSim();document.getElementById('simmodal').classList.add('on');}
function closeSim(){document.getElementById('simmodal').classList.remove('on');}
function resetSim(){const s=window._simB;document.getElementById('sim-apport').value=10;document.getElementById('sim-duree').value=simDuree();document.getElementById('sim-taux').value=s.taux||3.4;recalcSim();}
function recalcSim(){const s=window._simB;if(!s)return;
  const ap=+document.getElementById('sim-apport').value, an=+document.getElementById('sim-duree').value, tx=+document.getElementById('sim-taux').value;
  const apport=Math.round(s.net*ap/100), fin=Math.max(0,s.montant-apport), n=an*12, r=tx/100/12;
  const mens=r===0?(fin/n):(fin*r/(1-Math.pow(1+r,-n)));
  const effort=mens-s.loyer, pct=s.loyer>0?effort/s.loyer*100:0, interets=Math.max(0,mens*n-fin);
  document.getElementById('sim-apport-v').textContent=fmtEur(apport);
  document.getElementById('sim-apport-pct').textContent=ap+' %';
  document.getElementById('sim-duree-v').textContent=an+' ans';
  document.getElementById('sim-taux-v').textContent=tx.toFixed(2).replace('.',',')+' %';
  document.getElementById('sim-fin').textContent=fmtEur(fin);
  document.getElementById('sim-mens').textContent=fmtEur(Math.round(mens));
  document.getElementById('sim-effort').textContent=(effort>=0?'+ ':'− ')+fmtEur(Math.abs(Math.round(effort)));
  const pe=document.getElementById('sim-pct');pe.textContent=(pct>=0?'+ ':'− ')+Math.abs(Math.round(pct))+' %';pe.style.color=pct>20?'#c0392b':(pct<=10?'#1e8a5a':'');
  document.getElementById('sim-int').textContent=fmtEur(Math.round(interets));}
['sim-apport','sim-duree','sim-taux'].forEach(id=>document.getElementById(id).addEventListener('input',recalcSim));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(document.getElementById('simmodal').classList.contains('on'))closeSim();else closeBien();}});
</script>
<?php require_once __DIR__ . '/geo_views_modal.php'; /* modal 3 vues (Plan · Street View · 3D) — window.openGeoViews */ ?>
</body></html>
