# LoxBerry Philips Hue API v2 Plugin

Dieses Projekt stellt einen Grundstein für ein LoxBerry Plugin bereit, das die Philips Hue
Bridge (API v2) anbindet und die wichtigsten Ressourcen für Loxone bereitstellt. Über
einen kleinen REST-Server lassen sich Lampen, Szenen und Räume abfragen sowie Aktionen
(z. B. das Aktivieren einer Szene oder das Schalten eines Lichts) auslösen.

## Funktionsumfang

* Abfrage von Hue-Lampen, -Szenen und -Räumen über die REST-Schnittstelle.
* Aktivieren von Szenen und Setzen grundlegender Lampen-Parameter (Ein/Aus, Helligkeit).
* Verwaltung mehrerer Hue Bridges samt Application-/Client-Key direkt in der Weboberfläche.
* Kontextbezogene Anzeige: Szenen zeigen den verknüpften Raum, Lampen listen beteiligte Räume
  und Szenen auf.
* Konfigurationsdatei und Umgebungsvariable zur einfachen Anpassung auf dem LoxBerry.

## Vorbereitung

1. Erzeuge bzw. erhalte einen **Application Key** für deine Hue Bridge über das
   offizielle Philips-Entwicklerportal.
2. Trage deine Bridges in `config/config.json` ein oder setze die Umgebungsvariable
   `HUE_PLUGIN_CONFIG` auf den Pfad einer eigenen Datei.
   Das JSON unterstützt mehrere Einträge unter `bridges`:

   ```json
   {
     "bridges": [
       {
         "id": "wohnzimmer",
         "name": "Wohnzimmer",
         "bridge_ip": "192.168.1.50",
         "application_key": "<dein-app-key>",
         "client_key": null,
         "use_https": true,
         "verify_tls": false
       }
     ]
   }
   ```
3. Installiere die Python-Abhängigkeiten (z. B. innerhalb eines Virtual Environments):

   ```bash
   pip install -e ".[test]"
   ```

## Starten des REST-Servers

Zum Starten des Servers, der Loxone die Hue-Ressourcen bereitstellt, kannst du das
Startskript verwenden:

```bash
bin/run_server.sh
```

Der Server lauscht standardmäßig auf Port `5510`. Über die folgenden Endpunkte kannst du
mit der Hue Bridge interagieren:

| Methode | Pfad                         | Beschreibung                           |
|---------|------------------------------|----------------------------------------|
| GET     | `/lights?bridge_id=<id>`     | Liste aller Lampen                     |
| POST    | `/lights/{id}/state`         | Licht schalten / dimmen                |
| GET     | `/scenes?bridge_id=<id>`     | Liste aller Szenen                     |
| POST    | `/scenes/{id}/activate`      | Szene aktivieren                       |
| GET     | `/rooms?bridge_id=<id>`      | Liste aller Räume (Areas/Zonen)        |

Die LoxBerry-Weboberfläche spricht den Dienst standardmäßig über
`http://127.0.0.1:5510` an. Wenn du den Hue-Dienst auf einem anderen Host oder Port
betreibst, kannst du dies über die Umgebungsvariablen
`HUE_PLUGIN_SERVICE_HOST` und `HUE_PLUGIN_SERVICE_PORT` anpassen.
Zusätzlich bleibt die CORS-Variable `HUE_PLUGIN_ALLOW_ORIGINS` verfügbar, falls du
die REST-API dennoch direkt aus anderen Anwendungen heraus ansprechen möchtest.

## Weboberfläche im LoxBerry

Nach der Installation erscheint das Plugin in der LoxBerry-Systemsteuerung. Beim
Aufruf wird automatisch die Datei `webfrontend/html/index.php` geladen, die die
grafische Oberfläche ausliefert. Dort kannst du

* Hue Bridges samt Application-Key anlegen, bearbeiten und zwischen ihnen wechseln,
* die Verbindung zum lokalen REST-Dienst testen,
* Lampen, Räume und Szenen direkt auslesen,
* sowie einzelne Lampen oder Szenen zum Testen schalten.

Die Oberfläche lädt die Bridge-Liste automatisch und kommuniziert über einen
PHP-Proxy mit dem lokalen REST-Dienst. Damit entfällt die manuelle Eingabe einer
Basis-URL. Anpassungen an Host oder Port erfolgen ausschließlich über die oben
genannten Umgebungsvariablen.

### TLS-Zertifikate der Hue Bridge

Hue Bridges verwenden bei HTTPS-Verbindungen üblicherweise ein selbst signiertes
Zertifikat. Standardmäßig prüft das Plugin dieses Zertifikat **nicht**, damit die
Verbindung ohne zusätzlichen Pflegeaufwand funktioniert (`verify_tls = false`).
Wenn du die Option „Zertifikat prüfen“ aktivierst und anschließend eine Meldung
wie „Zertifikat konnte nicht verifiziert werden“ erhältst, stehen dir zwei
Möglichkeiten offen:

1. Deaktiviere die Zertifikatsprüfung in der Bridge-Konfiguration.
2. Importiere das Stammzertifikat der Hue Bridge auf deinem LoxBerry und lasse die
   Option aktiviert.

Der Verbindungstest fängt TLS-Fehler jetzt ab und weist mit einer verständlichen
Fehlermeldung auf diese beiden Optionen hin.

## Plugin-Paket für LoxBerry

GitHub-Kompatibilität beschränkt die Bereitstellung fertiger ZIP-Archive in diesem
Repository. Über das Skript `build_plugin_zip.sh` kannst du dennoch mit einem Kommando
ein installierbares Archiv erzeugen:

```bash
./build_plugin_zip.sh
```

Der erzeugte Pfad (z. B. `dist/hueapiv2-<commit>.zip`) wird auf der Konsole ausgegeben
und lässt sich direkt über die LoxBerry-Weboberfläche installieren.

Alternativ kannst du das Archiv manuell bauen. Der folgende Befehl erstellt ebenfalls
eine ZIP-Datei mit dem korrekten Verzeichnispräfix:

```bash
git archive --format=zip --output "hueapiv2-<version>.zip" --prefix "hueapiv2/" HEAD
```

Während der Installation sorgt `postroot.sh` dafür, dass ein virtuelles
Python-Umfeld angelegt und das Plugin darin installiert wird.

## Einbindung in Loxone

Innerhalb von Loxone kann dieser Dienst beispielsweise über HTTP-Kommandos angesteuert
werden. Die Weboberfläche zeigt dir alle relevanten IDs inklusive Raum- und Szenenbezug an.
Mit diesen Informationen kannst du aus dem Miniserver heraus Szenen aktivieren oder Lampen
schalten – wahlweise über das REST-Backend auf Port `5510` oder direkt über das Plugin-
Frontend.

### Szenen oder Lampen per HTTP-Request auslösen

Für einfache Integrationen (z. B. über einen virtuellen Ausgang) kann Loxone die
Authentifizierungs-geschützte Plugin-Seite direkt ansprechen. Beispiel zum Aktivieren einer
Szene:

```
http://<loxberry-host>/admin/plugins/hueapiv2/index.php?ajax=1&action=scene_command&bridge_id=<bridge-id>&scene_id=<scene-rid>
```

Optional kannst du einen Zielraum oder eine Zone angeben (Parameter `target_rid` und
`target_rtype`). Das Plugin antwortet mit einer JSON-Struktur; ein erfolgreicher Aufruf liefert
`{"ok": true}`.

Zum Schalten einer Lampe steht derselbe Mechanismus bereit:

```
http://<loxberry-host>/admin/plugins/hueapiv2/index.php?ajax=1&action=light_command&bridge_id=<bridge-id>&light_id=<light-rid>&on=1&brightness=75
```

Die Parameter `on` (`1` oder `0`) und `brightness` (0–100) sind optional. Alternativ kannst du
den Python-REST-Dienst weiterverwenden, wenn du lieber auf Port `5510` mit JSON arbeitest.

## Tests

Für zentrale Funktionen (z. B. das Laden der Konfiguration) existieren Unit-Tests, die
mit folgendem Befehl ausgeführt werden können:

```bash
pytest
```

## Lizenz

Dieses Projekt steht ohne Gewähr zur Verfügung. Passe den Code nach Bedarf für dein
konkretes Setup an.
