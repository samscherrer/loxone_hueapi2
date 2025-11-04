<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
  <head>
    <meta charset="utf-8" />
    <title>Hue API v2 Bridge (auth)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
      body {
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        margin: 0;
        padding: 3rem 1.5rem;
        background: #111827;
        color: #f9fafb;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        text-align: center;
      }

      .card {
        max-width: 640px;
        background: rgba(17, 24, 39, 0.85);
        border-radius: 16px;
        padding: 2.5rem 2rem;
        border: 1px solid rgba(59, 130, 246, 0.35);
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.45);
      }

      h1 {
        margin-top: 0;
        font-size: clamp(2rem, 4vw, 2.75rem);
        letter-spacing: 0.015em;
        color: #60a5fa;
      }

      p {
        font-size: 1.05rem;
        line-height: 1.6;
        margin: 0.75rem 0 0;
      }

      a {
        color: #93c5fd;
      }
    </style>
  </head>
  <body>
    <main class="card">
      <h1>Geschützter Bereich</h1>
      <p>
        Diese Ansicht ist für authentifizierte LoxBerry-Benutzer vorgesehen. Bitte
        verwende die reguläre Plugin-Oberfläche unter
        <a href="../html/index.php">/html/index.php</a> oder melde dich im
        LoxBerry-Frontend an, falls du Zugriff auf weitergehende Funktionen
        erwartest.
      </p>
    </main>
  </body>
</html>
