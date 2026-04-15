<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bouton Rechercher premium</title>
  <style>
    body{
      margin:0;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#edf3fb;
      font-family:Inter, Arial, sans-serif;
    }

    .btn-rechercher{
      --top:#5ea0bf;
      --mid:#2e6c88;
      --deep:#163b4f;
      --text:#ffffff;
      --glow:#d8fff4;

      position:relative;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:260px;
      min-height:74px;
      padding:0 42px;
      border:none;
      border-radius:999px;
      cursor:pointer;
      color:var(--text);
      font-size:2rem;
      font-weight:700;
      letter-spacing:.01em;
      text-decoration:none;
      background:
        linear-gradient(180deg, rgba(255,255,255,.24) 0%, rgba(255,255,255,0) 30%),
        linear-gradient(145deg, var(--top) 0%, var(--mid) 44%, var(--deep) 100%);
      box-shadow:
        0 20px 38px rgba(17, 47, 63, 0.34),
        0 0 0 1px rgba(255,255,255,.18) inset,
        0 2px 0 rgba(255,255,255,.22) inset,
        0 -10px 18px rgba(0,0,0,.26) inset;
      overflow:hidden;
      isolation:isolate;
      transition:
        transform .22s ease,
        box-shadow .22s ease,
        filter .22s ease;
    }

    .btn-rechercher::before{
      content:"";
      position:absolute;
      top:7%;
      left:8%;
      width:84%;
      height:42%;
      border-radius:999px;
      background:linear-gradient(180deg, rgba(255,255,255,.50), rgba(255,255,255,0));
      filter:blur(2px);
      opacity:.95;
      pointer-events:none;
    }

    .btn-rechercher::after{
      content:"";
      position:absolute;
      left:50%;
      bottom:-14px;
      transform:translateX(-50%);
      width:68%;
      height:28px;
      border-radius:999px;
      background:
        radial-gradient(ellipse at center,
          rgba(216,255,244,.95) 0%,
          rgba(216,255,244,.55) 35%,
          rgba(216,255,244,0) 75%);
      filter:blur(7px);
      pointer-events:none;
      z-index:-1;
    }

    .btn-rechercher:hover{
      transform:translateY(-2px) scale(1.015);
      filter:saturate(1.05);
      box-shadow:
        0 26px 44px rgba(17, 47, 63, 0.40),
        0 0 28px rgba(122, 224, 210, 0.20),
        0 0 0 1px rgba(255,255,255,.20) inset,
        0 2px 0 rgba(255,255,255,.24) inset,
        0 -10px 18px rgba(0,0,0,.28) inset;
    }

    .btn-rechercher:active{
      transform:translateY(1px) scale(.992);
      box-shadow:
        0 12px 22px rgba(17, 47, 63, 0.30),
        0 0 0 1px rgba(255,255,255,.16) inset,
        0 1px 0 rgba(255,255,255,.18) inset,
        0 -6px 12px rgba(0,0,0,.22) inset;
    }
  </style>
</head>
<body>
  <button class="btn-rechercher">Rechercher</button>
</body>
</html>