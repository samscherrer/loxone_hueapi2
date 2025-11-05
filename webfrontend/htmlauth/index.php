<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
  <head>
    <meta charset="utf-8" />
    <title>Philips Hue API v2 Bridge</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
      :root {
        color-scheme: light dark;
        --accent: #005a9c;
        --accent-light: #e2f1ff;
        --card-bg: rgba(255, 255, 255, 0.9);
        --card-border: rgba(0, 0, 0, 0.08);
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }

      body {
        margin: 0;
        background: linear-gradient(160deg, #101935 0%, #1f2a44 40%, #0f1a2c 100%);
        min-height: 100vh;
        color: #111;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 2.5rem 1.5rem 3rem;
        box-sizing: border-box;
      }

      header {
        text-align: center;
        color: #fff;
        margin-bottom: 2rem;
      }

      header h1 {
        font-size: clamp(2rem, 4vw, 2.8rem);
        margin: 0 0 0.5rem;
        font-weight: 600;
      }

      header p {
        margin: 0;
        font-size: 1rem;
        opacity: 0.8;
      }

      main {
        width: min(940px, 100%);
        display: grid;
        gap: 1.5rem;
      }

      .card {
        background: var(--card-bg);
        border-radius: 16px;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.25);
        border: 1px solid var(--card-border);
        padding: 1.6rem;
        backdrop-filter: blur(6px);
      }

      .card h2 {
        margin-top: 0;
        font-size: 1.4rem;
        color: var(--accent);
      }

      label {
        font-weight: 600;
        display: block;
        margin-bottom: 0.25rem;
      }

      input,
      select,
      button,
      textarea {
        font: inherit;
      }

      input[type="text"],
      textarea,
      select {
        width: 100%;
        box-sizing: border-box;
        padding: 0.6rem 0.75rem;
        border-radius: 10px;
        border: 1px solid rgba(0, 0, 0, 0.2);
        background: rgba(255, 255, 255, 0.92);
        transition: border 0.2s ease, box-shadow 0.2s ease;
        color: #0f172a;
      }

      input:focus,
      textarea:focus,
      select:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-light);
      }

      input::placeholder,
      textarea::placeholder {
        color: rgba(15, 23, 42, 0.55);
      }

      button {
        padding: 0.6rem 1.2rem;
        border-radius: 999px;
        border: none;
        background: var(--accent);
        color: #fff;
        cursor: pointer;
        font-weight: 600;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
      }

      button.secondary {
        background: rgba(16, 25, 53, 0.08);
        color: var(--accent);
      }

      button.danger {
        background: rgba(185, 28, 28, 0.15);
        color: #991b1b;
      }

      button:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.18);
      }

      .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.8rem;
        margin-top: 1rem;
      }

      .grid {
        display: grid;
        gap: 1rem;
      }

      @media (min-width: 720px) {
        .grid.two {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      pre {
        background: rgba(0, 0, 0, 0.06);
        border-radius: 12px;
        padding: 1rem;
        overflow-x: auto;
        margin: 0;
        font-size: 0.9rem;
      }

      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0.75rem;
        font-size: 0.95rem;
      }

      th,
      td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        text-align: left;
      }

      tbody tr:hover {
        background: rgba(0, 90, 156, 0.06);
      }

      .tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        background: rgba(0, 90, 156, 0.1);
        color: var(--accent);
        font-size: 0.8rem;
        font-weight: 600;
      }

      .message {
        margin-top: 1rem;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        display: none;
        font-weight: 600;
      }

      .message.error {
        background: rgba(220, 38, 38, 0.12);
        color: #8b1d1d;
      }

      .message.success {
        background: rgba(34, 197, 94, 0.15);
        color: #126333;
      }

      .muted {
        opacity: 0.8;
        font-size: 0.9rem;
      }

      .form-checks {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 0.75rem;
      }

      .form-checks label {
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        margin: 0;
      }

      .form-checks input[type="checkbox"] {
        width: auto;
        accent-color: var(--accent);
        transform: scale(1.1);
      }

      .bridge-items {
        list-style: none;
        padding: 0;
        margin: 0.75rem 0 0;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .bridge-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0.75rem;
        border-radius: 10px;
        background: rgba(15, 23, 42, 0.05);
      }

      .bridge-item strong {
        color: var(--accent);
      }

      .bridge-actions {
        display: flex;
        gap: 0.5rem;
      }

      button[hidden] {
        display: none;
      }

      footer {
        margin-top: 2rem;
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.85rem;
      }
    </style>
  </head>
  <body>
    <header>
      <h1>Philips Hue API v2 Bridge</h1>
      <p>
        Verwalte deine Hue-Lampen, Räume und Szenen direkt aus LoxBerry heraus.
      </p>
    </header>
    <main>
      <section class="card" id="bridge-card">
        <h2>Hue Bridges verwalten</h2>
        <p class="muted">
          Hinterlege hier die IP-Adresse und den <em>application key</em> deiner Philips
          Hue Bridges. Du kannst mehrere Bridges anlegen und später im Interface
          auswählen.
        </p>
        <div id="bridge-list" class="muted">
          Noch keine Verbindung zur Hue-Bridge-API hergestellt. Gib unten die
          Basis-URL ein und lade anschließend die Bridge-Liste.
        </div>
        <form id="bridge-form" class="grid">
          <input id="bridge-id" type="hidden" />
          <div class="grid two">
            <div>
              <label for="bridge-name">Anzeigename</label>
              <input id="bridge-name" type="text" placeholder="z. B. Wohnzimmer" />
            </div>
            <div>
              <label for="bridge-ip">Bridge-Adresse</label>
              <input
                id="bridge-ip"
                type="text"
                placeholder="192.168.1.50 oder bridge.local"
                required
              />
            </div>
          </div>
          <label for="bridge-app-key">Hue Application Key</label>
          <input
            id="bridge-app-key"
            type="text"
            placeholder="Hue App Key (z. B. 40 Zeichen)"
            required
          />
          <label for="bridge-client-key">Hue Client Key (optional)</label>
          <input
            id="bridge-client-key"
            type="text"
            placeholder="Nur für Entertainment-Verbindungen erforderlich"
          />
          <div class="form-checks">
            <label>
              <input id="bridge-use-https" type="checkbox" checked />
              HTTPS verwenden
            </label>
            <label>
              <input id="bridge-verify-tls" type="checkbox" />
              TLS-Zertifikate prüfen
            </label>
          </div>
          <div class="actions">
            <button type="submit" id="bridge-save">Bridge speichern</button>
            <button type="button" id="bridge-reset" class="secondary">
              Formular leeren
            </button>
            <button type="button" id="bridge-delete" class="danger" hidden>
              Bridge entfernen
            </button>
          </div>
          <div id="bridge-message" class="message"></div>
        </form>
      </section>

      <section class="card" id="connection-card">
        <h2>Verbindung zur Bridge-API</h2>
        <p class="muted">
          Der Bridge-Dienst läuft standardmäßig auf Port <strong>5510</strong> desselben
          LoxBerry-Systems. Passe die Adresse an, falls du sie verändert hast.
        </p>
        <label for="bridge-select">Aktive Hue Bridge</label>
        <select id="bridge-select"></select>
        <label for="base-url">Basis-URL</label>
        <input id="base-url" type="text" placeholder="http://loxberry:5510" />
        <div class="actions">
          <button type="button" id="load-bridges">Bridge-Liste laden</button>
          <button type="button" id="test-connection">Verbindung testen</button>
          <button type="button" id="use-default-base" class="secondary">
            Standard-Adresse übernehmen
          </button>
          <button type="button" id="open-docs" class="secondary">
            API-Dokumentation öffnen
          </button>
        </div>
        <div id="connection-message" class="message"></div>
      </section>

      <section class="card">
        <h2>Ressourcen erkunden</h2>
        <p class="muted">
          Lade die aktuell von der Hue Bridge gemeldeten Ressourcen. Mit einem Klick
          werden die Daten direkt von der lokalen REST-API geladen.
        </p>
        <div class="actions">
          <button data-endpoint="lights" class="load-resource">Lampen</button>
          <button data-endpoint="rooms" class="load-resource">Räume</button>
          <button data-endpoint="scenes" class="load-resource">Szenen</button>
        </div>
        <div id="resource-message" class="message"></div>
        <div id="resource-output">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Ressourcen-ID</th>
                <th>Typ</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="4" class="muted">
                  Wähle oben eine Kategorie, um die Daten zu laden.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card grid two">
        <div>
          <h2>Lampe schalten</h2>
          <label for="light-id">Lampenzuordnung (RID)</label>
          <input id="light-id" type="text" placeholder="z. B. 12345678-90ab" />
          <label for="light-action">Aktion</label>
          <select id="light-action">
            <option value="on">Einschalten</option>
            <option value="off">Ausschalten</option>
          </select>
          <label for="light-brightness">Helligkeit (0-100, optional)</label>
          <input id="light-brightness" type="text" inputmode="numeric" />
          <div class="actions">
            <button id="light-submit">Senden</button>
          </div>
          <div id="light-message" class="message"></div>
        </div>
        <div>
          <h2>Szene aktivieren</h2>
          <label for="scene-id">Szenen-RID</label>
          <input id="scene-id" type="text" placeholder="RID der Szene" />
          <label for="scene-target">Optionales Ziel (RID::rtype)</label>
          <input id="scene-target" type="text" placeholder="&lt;resource-id&gt;::&lt;rtype&gt;" />
          <div class="actions">
            <button id="scene-submit">Aktivieren</button>
          </div>
          <div id="scene-message" class="message"></div>
        </div>
      </section>

      <section class="card">
        <h2>Konfiguration bearbeiten</h2>
        <p class="muted">
          Du findest die Einstellungen unter <code>config/config.json</code> im
          Plugin-Verzeichnis. Beispiel:
        </p>
        <pre>{
  "bridge_ip": "192.168.1.50",
  "application_key": "dein-langer-hue-app-key",
  "api_port": 5510
}</pre>
        <p class="muted">
          Nach Änderungen starte den Dienst über <code>Service</code> →
          <code>Plugin-Dienste</code> neu oder führe das Skript
          <code>bin/run_server.sh</code> aus.
        </p>
      </section>
    </main>

    <footer>
      Tipp: Wenn du ein anderes Muster-Plugin ansehen möchtest, öffne die LoxBerry
      Dokumentation unter <code>https://loxwiki.atlassian.net/wiki</code>.
    </footer>

    <script>
      const baseUrlInput = document.getElementById("base-url");
      const bridgeSelect = document.getElementById("bridge-select");
      const bridgeList = document.getElementById("bridge-list");
      const bridgeForm = document.getElementById("bridge-form");
      const bridgeIdField = document.getElementById("bridge-id");
      const bridgeNameInput = document.getElementById("bridge-name");
      const bridgeIpInput = document.getElementById("bridge-ip");
      const bridgeAppKeyInput = document.getElementById("bridge-app-key");
      const bridgeClientKeyInput = document.getElementById("bridge-client-key");
      const bridgeUseHttpsInput = document.getElementById("bridge-use-https");
      const bridgeVerifyTlsInput = document.getElementById("bridge-verify-tls");
      const bridgeMessage = document.getElementById("bridge-message");
      const bridgeDeleteButton = document.getElementById("bridge-delete");
      const bridgeResetButton = document.getElementById("bridge-reset");
      const loadBridgesButton = document.getElementById("load-bridges");
      const useDefaultBaseButton = document.getElementById("use-default-base");
      const connectionMessage = document.getElementById("connection-message");
      const resourceMessage = document.getElementById("resource-message");
      const lightMessage = document.getElementById("light-message");
      const sceneMessage = document.getElementById("scene-message");

      let bridges = [];
      let activeBridgeId = null;
      let hasAttemptedBridgeLoad = false;

      const message = (el, type, text) => {
        if (!el) {
          return;
        }
        el.textContent = text;
        el.className = type ? `message ${type}` : "message";
        el.style.display = text ? "block" : "none";
      };

      const defaultBaseUrl = () => {
        const hostname = window.location.hostname || "loxberry";
        return `http://${hostname}:5510`;
      };

      const readBaseUrl = () => baseUrlInput.value.trim().replace(/\/$/, "");

      const ensureBaseUrlConfigured = (targetMessage) => {
        const value = readBaseUrl();
        if (!value) {
          message(
            targetMessage,
            "error",
            "Bitte gib zuerst die Basis-URL des Hue-Dienstes ein."
          );
          baseUrlInput.focus();
          return null;
        }
        return value;
      };

      const apiFetch = async (
        path,
        { method = "GET", body, includeBridge = false } = {}
      ) => {
        const base = readBaseUrl();
        if (!base) {
          throw new Error("Keine Basis-URL konfiguriert.");
        }
        const url = new URL(`${base}${path}`);
        if (includeBridge && activeBridgeId) {
          url.searchParams.set("bridge_id", activeBridgeId);
        }
        const options = {
          method,
          headers: { "Content-Type": "application/json" },
        };
        if (body !== undefined) {
          options.body = typeof body === "string" ? body : JSON.stringify(body);
        }
        const response = await fetch(url.toString(), options);
        if (!response.ok) {
          const details = await response.text();
          throw new Error(
            `HTTP ${response.status}: ${details || response.statusText}`
          );
        }
        if (response.status === 204) {
          return null;
        }
        const contentType = response.headers.get("content-type") || "";
        if (contentType.includes("application/json")) {
          return response.json();
        }
        return response.text();
      };

      const hueFetch = (path, options = {}) =>
        apiFetch(path, { includeBridge: true, ...options });

      const ensureBridgeSelected = (targetMessage) => {
        if (!activeBridgeId) {
          message(
            targetMessage,
            "error",
            "Bitte lege zuerst eine Hue Bridge an und wähle sie aus."
          );
          return false;
        }
        return true;
      };

      const renderBridgeSelect = () => {
        bridgeSelect.innerHTML = "";
        if (!bridges.length) {
          bridgeSelect.disabled = true;
          const option = document.createElement("option");
          option.textContent = hasAttemptedBridgeLoad
            ? "Keine Bridge konfiguriert"
            : "Bridge-Liste noch nicht geladen";
          option.disabled = true;
          option.value = "";
          option.selected = true;
          bridgeSelect.appendChild(option);
          return;
        }
        bridgeSelect.disabled = false;
        bridges.forEach((bridge) => {
          const option = document.createElement("option");
          option.value = bridge.id;
          option.textContent = bridge.name
            ? `${bridge.name} (${bridge.id})`
            : bridge.id;
          if (bridge.id === activeBridgeId) {
            option.selected = true;
          }
          bridgeSelect.appendChild(option);
        });
      };

      const renderBridgeList = () => {
        if (!bridges.length) {
          bridgeList.innerHTML = hasAttemptedBridgeLoad
            ? "<p class=\"muted\">Noch keine Bridge hinterlegt. Nutze das Formular, um eine Verbindung anzulegen.</p>"
            : "<p class=\"muted\">Bridge-Liste noch nicht geladen. Gib unten die Basis-URL ein und klicke auf \"Bridge-Liste laden\".</p>";
          return;
        }
        const list = document.createElement("ul");
        list.className = "bridge-items";
        bridges.forEach((bridge) => {
          const item = document.createElement("li");
          item.className = "bridge-item";
          const info = document.createElement("div");
          info.innerHTML = `<strong>${bridge.name || bridge.id}</strong><br /><span class="muted">${bridge.bridge_ip}</span>`;
          const actions = document.createElement("div");
          actions.className = "bridge-actions";

          const editButton = document.createElement("button");
          editButton.type = "button";
          editButton.className = "secondary";
          editButton.textContent = "Bearbeiten";
          editButton.addEventListener("click", () => fillBridgeForm(bridge.id));
          actions.appendChild(editButton);

          if (bridge.id === activeBridgeId) {
            const activeTag = document.createElement("span");
            activeTag.className = "tag";
            activeTag.textContent = "Aktiv";
            actions.appendChild(activeTag);
          } else {
            const activateButton = document.createElement("button");
            activateButton.type = "button";
            activateButton.className = "secondary";
            activateButton.textContent = "Aktiv setzen";
            activateButton.addEventListener("click", () =>
              setActiveBridge(bridge.id)
            );
            actions.appendChild(activateButton);
          }

          item.append(info, actions);
          list.appendChild(item);
        });
        bridgeList.innerHTML = "";
        bridgeList.appendChild(list);
      };

      const setActiveBridge = (bridgeId) => {
        activeBridgeId = bridgeId;
        renderBridgeSelect();
        renderBridgeList();
        if (bridgeSelect.value !== bridgeId) {
          bridgeSelect.value = bridgeId;
        }
      };

      const fillBridgeForm = (bridgeId) => {
        const bridge = bridges.find((b) => b.id === bridgeId);
        if (!bridge) {
          return;
        }
        bridgeIdField.value = bridge.id;
        bridgeNameInput.value = bridge.name || "";
        bridgeIpInput.value = bridge.bridge_ip || "";
        bridgeAppKeyInput.value = bridge.application_key || "";
        bridgeClientKeyInput.value = bridge.client_key || "";
        bridgeUseHttpsInput.checked = Boolean(bridge.use_https);
        bridgeVerifyTlsInput.checked = Boolean(bridge.verify_tls);
        bridgeDeleteButton.hidden = false;
        message(bridgeMessage, "", "");
      };

      const resetBridgeForm = () => {
        bridgeForm.reset();
        bridgeIdField.value = "";
        bridgeUseHttpsInput.checked = true;
        bridgeVerifyTlsInput.checked = false;
        bridgeDeleteButton.hidden = true;
        message(bridgeMessage, "", "");
      };

      bridgeResetButton.addEventListener("click", (event) => {
        event.preventDefault();
        resetBridgeForm();
      });

      bridgeDeleteButton.addEventListener("click", async () => {
        if (!ensureBaseUrlConfigured(bridgeMessage)) {
          return;
        }
        const bridgeId = bridgeIdField.value;
        if (!bridgeId) {
          return;
        }
        if (
          !window.confirm(
            `Bridge '${bridgeId}' wirklich entfernen? Diese Aktion kann nicht rückgängig gemacht werden.`
          )
        ) {
          return;
        }
        try {
          await apiFetch(`/config/bridges/${encodeURIComponent(bridgeId)}`, {
            method: "DELETE",
          });
          message(bridgeMessage, "success", "Bridge wurde entfernt.");
          await loadBridges();
          resetBridgeForm();
        } catch (error) {
          message(bridgeMessage, "error", error.message);
        }
      });

      bridgeForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!ensureBaseUrlConfigured(bridgeMessage)) {
          return;
        }
        const bridgeId = bridgeIdField.value.trim() || null;
        const payload = {
          name: bridgeNameInput.value.trim() || null,
          bridge_ip: bridgeIpInput.value.trim(),
          application_key: bridgeAppKeyInput.value.trim(),
          client_key: bridgeClientKeyInput.value.trim() || null,
          use_https: bridgeUseHttpsInput.checked,
          verify_tls: bridgeVerifyTlsInput.checked,
        };
        if (!payload.bridge_ip || !payload.application_key) {
          message(
            bridgeMessage,
            "error",
            "Bitte gib sowohl die Bridge-Adresse als auch den Application Key an."
          );
          return;
        }
        try {
          if (bridgeId) {
            await apiFetch(`/config/bridges/${encodeURIComponent(bridgeId)}`, {
              method: "PUT",
              body: payload,
            });
            message(bridgeMessage, "success", "Bridge wurde aktualisiert.");
          } else {
            await apiFetch("/config/bridges", {
              method: "POST",
              body: { ...payload, id: null },
            });
            message(bridgeMessage, "success", "Neue Bridge wurde angelegt.");
          }
          await loadBridges();
          if (!bridgeId) {
            resetBridgeForm();
          }
        } catch (error) {
          message(bridgeMessage, "error", error.message);
        }
      });

      bridgeSelect.addEventListener("change", (event) => {
        setActiveBridge(event.target.value);
      });

      baseUrlInput.addEventListener("change", () => {
        hasAttemptedBridgeLoad = false;
        bridges = [];
        activeBridgeId = null;
        renderBridgeSelect();
        renderBridgeList();
      });

      const loadBridges = async () => {
        if (!ensureBaseUrlConfigured(bridgeMessage)) {
          renderBridgeList();
          return;
        }
        hasAttemptedBridgeLoad = true;
        bridgeList.innerHTML =
          "<p class=\"muted\">Bridge-Liste wird geladen …</p>";
        try {
          const data = await apiFetch("/config/bridges");
          bridges = Array.isArray(data) ? data : [];
          if (!bridges.length) {
            activeBridgeId = null;
          } else if (
            !activeBridgeId ||
            !bridges.some((b) => b.id === activeBridgeId)
          ) {
            activeBridgeId = bridges[0].id;
          }
          renderBridgeSelect();
          renderBridgeList();
          if (activeBridgeId) {
            bridgeSelect.value = activeBridgeId;
          }
        } catch (error) {
          message(bridgeMessage, "error", error.message);
          bridges = [];
          activeBridgeId = null;
          renderBridgeSelect();
          renderBridgeList();
        }
      };

      const renderResources = (items) => {
        const tbody = document
          .querySelector("#resource-output tbody")
          .cloneNode(false);
        if (!items || items.length === 0) {
          const row = document.createElement("tr");
          const cell = document.createElement("td");
          cell.colSpan = 4;
          cell.textContent = "Keine Einträge gefunden.";
          cell.className = "muted";
          row.appendChild(cell);
          tbody.appendChild(row);
        } else {
          for (const item of items) {
            const row = document.createElement("tr");
            const name = document.createElement("td");
            name.textContent = item.name || "(ohne Namen)";
            const rid = document.createElement("td");
            rid.textContent = item.id;
            const type = document.createElement("td");
            type.innerHTML = `<span class="tag">${item.type}</span>`;
            const details = document.createElement("td");
            const button = document.createElement("button");
            button.textContent = "JSON anzeigen";
            button.className = "secondary";
            button.addEventListener("click", () => {
              alert(JSON.stringify(item, null, 2));
            });
            details.appendChild(button);
            row.append(name, rid, type, details);
            tbody.appendChild(row);
          }
        }
        document.querySelector("#resource-output tbody").replaceWith(tbody);
      };

      document.getElementById("test-connection").addEventListener("click", async () => {
        message(connectionMessage, "", "");
        if (!ensureBaseUrlConfigured(connectionMessage)) {
          return;
        }
        if (!ensureBridgeSelected(connectionMessage)) {
          return;
        }
        try {
          await hueFetch("/lights", { method: "GET" });
          message(connectionMessage, "success", "Verbindung erfolgreich getestet.");
        } catch (error) {
          message(
            connectionMessage,
            "error",
            `Verbindung fehlgeschlagen: ${error.message}`
          );
        }
      });

      document.getElementById("open-docs").addEventListener("click", () => {
        const base = readBaseUrl();
        if (!base) {
          message(
            connectionMessage,
            "error",
            "Bitte gib zuerst die Basis-URL des Hue-Dienstes ein."
          );
          baseUrlInput.focus();
          return;
        }
        window.open(`${base}/docs`, "_blank", "noopener");
      });

      document.querySelectorAll(".load-resource").forEach((button) => {
        button.addEventListener("click", async () => {
          message(resourceMessage, "", "");
          if (!ensureBaseUrlConfigured(resourceMessage)) {
            return;
          }
          if (!ensureBridgeSelected(resourceMessage)) {
            return;
          }
          const endpoint = button.getAttribute("data-endpoint");
          try {
            const data = await hueFetch(`/${endpoint}`);
            renderResources(data);
          } catch (error) {
            message(
              resourceMessage,
              "error",
              `Fehler beim Laden: ${error.message}`
            );
          }
        });
      });

      document.getElementById("light-submit").addEventListener("click", async () => {
        message(lightMessage, "", "");
        if (!ensureBaseUrlConfigured(lightMessage)) {
          return;
        }
        if (!ensureBridgeSelected(lightMessage)) {
          return;
        }
        const lightId = document.getElementById("light-id").value.trim();
        if (!lightId) {
          message(lightMessage, "error", "Bitte eine Lampen-RID angeben.");
          return;
        }
        const action = document.getElementById("light-action").value;
        const brightnessRaw = document
          .getElementById("light-brightness")
          .value.trim();
        const payload = { on: action === "on" };
        if (brightnessRaw !== "") {
          const value = Number.parseInt(brightnessRaw, 10);
          if (Number.isNaN(value) || value < 0 || value > 100) {
            message(
              lightMessage,
              "error",
              "Helligkeit muss zwischen 0 und 100 liegen."
            );
            return;
          }
          payload.brightness = value;
        }
        try {
          await hueFetch(`/lights/${encodeURIComponent(lightId)}/state`, {
            method: "POST",
            body: payload,
          });
          message(lightMessage, "success", "Befehl wurde an die Bridge gesendet.");
        } catch (error) {
          message(lightMessage, "error", `Aktion fehlgeschlagen: ${error.message}`);
        }
      });

      document.getElementById("scene-submit").addEventListener("click", async () => {
        message(sceneMessage, "", "");
        if (!ensureBaseUrlConfigured(sceneMessage)) {
          return;
        }
        if (!ensureBridgeSelected(sceneMessage)) {
          return;
        }
        const sceneId = document.getElementById("scene-id").value.trim();
        if (!sceneId) {
          message(sceneMessage, "error", "Bitte eine Szenen-RID angeben.");
          return;
        }
        const targetValue = document.getElementById("scene-target").value.trim();
        const payload = {};
        if (targetValue) {
          const [rid, rtype] = targetValue.split("::");
          if (!rid || !rtype) {
            message(
              sceneMessage,
              "error",
              "Bitte Ziel im Format <resource-id>::<rtype> angeben."
            );
            return;
          }
          payload.target_rid = rid;
          payload.target_rtype = rtype;
        }
        try {
          await hueFetch(`/scenes/${encodeURIComponent(sceneId)}/activate`, {
            method: "POST",
            body: payload,
          });
          message(sceneMessage, "success", "Szene wurde angefordert.");
        } catch (error) {
          message(sceneMessage, "error", `Aktion fehlgeschlagen: ${error.message}`);
        }
      });

      loadBridgesButton.addEventListener("click", async () => {
        message(bridgeMessage, "", "");
        await loadBridges();
      });

      useDefaultBaseButton.addEventListener("click", () => {
        baseUrlInput.value = defaultBaseUrl();
      });
    </script>
  </body>
</html>
