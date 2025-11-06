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
* Ressourcenliste inklusive Lampen, Szenen, Räumen, Schaltern und Bewegungsmeldern – ideal, um
  die benötigten Hue-RIDs für Loxone zu kopieren.
* Speichern des bevorzugten Loxone-Pfads (öffentlich/Admin) sowie optionaler Zugangsdaten für
  virtuelle Ausgänge direkt in der Oberfläche.
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
* Lampen, Räume, Szenen sowie Schalter und Bewegungsmelder direkt auslesen,
* sowie einzelne Lampen oder Szenen zum Testen schalten.
* Pfad und optionale Zugangsdaten für Loxone-Ausgänge speichern, um fertige HTTP-Kommandos
  schneller zu übernehmen.

Die Oberfläche lädt die Bridge-Liste automatisch und kommuniziert über einen
PHP-Proxy mit dem lokalen REST-Dienst. Damit entfällt die manuelle Eingabe einer
Basis-URL. Anpassungen an Host oder Port erfolgen ausschließlich über die oben
genannten Umgebungsvariablen.

Der Bereich **„Hue-Ressourcen anzeigen“** stellt separate Buttons für Lampen,
Szenen, Räume, Schalter und Bewegungsmelder bereit. So kannst du die jeweiligen
Resource-IDs (RID) schnell ablesen oder dir per „JSON anzeigen“ die vollständigen
Hue-Daten anzeigen lassen – ideal, um Schalter oder Bewegungsmelder mit virtuellen
Eingängen in Loxone zu verknüpfen.

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

Innerhalb von Loxone kann dieser Dienst über einfache HTTP-Kommandos angesteuert werden.
Die Weboberfläche zeigt dir alle relevanten IDs inklusive Raum- und Szenenbezug an und
erzeugt für jede Bridge direkt kopierbare URLs für virtuelle Ausgänge – sowohl für das
Einschalten (Wert `1`) als auch zum Ausschalten (Wert `0`). Du kannst zusätzlich auswählen,
ob die Befehle über den öffentlichen `/plugins/...`-Pfad (ohne Login) oder den geschützten
`/admin/plugins/...`-Pfad laufen sollen. Optional lassen sich HTTP-Benutzername und Passwort
für Basic Auth hinterlegen, die nur in den generierten URLs erscheinen. Wichtig: Loxone
soll die HTTP-Methode **POST** verwenden. Lege im Hauptelement deines virtuellen Ausgangs
außerdem die komplette Basis-URL inklusive Zugangsdaten an (z. B.
`http://loxberry:passwort@loxberry-host`).

### Vorgehen in Loxone (Virtueller Ausgang)

1. Lege im Miniserver einen **virtuellen Ausgang** an, der auf das LoxBerry-System zeigt
   (IP-Adresse = LoxBerry, Port = `80`).
2. Entscheide im Abschnitt „Loxone-Ausgänge vorbereiten“ der Plugin-Oberfläche, ob du die
   öffentliche URL oder den geschützten Admin-Pfad verwenden möchtest. Falls dein LoxBerry
   einen Login verlangt, kannst du dort auch Benutzername und Passwort angeben – sie werden
   lediglich in die erzeugten URLs eingetragen. Speichere die Eingaben anschließend mit
   „Einstellungen speichern“ im Abschnitt darunter.
3. Erstelle unter dem virtuellen Ausgang in Loxone einen **virtuellen Ausgangsbefehl** und
   trage als Kommando die URL aus dem Plugin ein. Verwende die Methode **POST** und aktiviere
   die Optionen „Befehl bei EIN ausführen“ und „Befehl bei AUS ausführen“, falls du beide
   Fälle bedienen möchtest.
4. Hinterlege im Abschnitt „Loxone-Miniserver“ der Plugin-Oberfläche die Basis-URL deines
   Miniservers (inklusive `http://benutzer:passwort@...`). Diese Angaben nutzt das Plugin,
   um Hue-Sensorereignisse als virtuelle Eingänge an Loxone weiterzuleiten.
5. Kopiere die `light_id` bzw. `scene_id` direkt aus der Plugin-Oberfläche. Die Seite zeigt
   unter „Licht steuern“ und „Szene aktivieren“ automatisch die passenden URLs für EIN (`1`)
   und AUS (`0`) an.
6. Wiederhole die Schritte für weitere Lampen oder Szenen. Durch die Bridge-Auswahl kannst du
   unterschiedliche Hue Bridges getrennt verwalten.

### Szenen oder Lampen per HTTP-Request auslösen

Für einfache Integrationen (z. B. über einen virtuellen Ausgang) kannst du den öffentlich
erreichbaren Pfad unter `/plugins/<plugin-ordner>/index.php` nutzen. So vermeidest du zusätzliche
HTTP-Authentifizierung auf `/admin/plugins/...`. Beispiel zum Aktivieren bzw. Ausschalten einer
Szene (Wert `1` aktiviert, Wert `0` schaltet den Zielbereich aus):

```
http://<loxberry-host>/plugins/hueapiv2/index.php?ajax=1&action=scene_command&bridge_id=<bridge-id>&scene_id=<scene-rid>&state=1
```

Optional kannst du einen Zielraum oder eine Zone angeben (Parameter `target_rid` und
`target_rtype`). Damit stellst du sicher, dass die Szene in dem gewünschten Bereich aktiviert
bzw. beim Ausschalten vollständig deaktiviert wird:

```
http://<loxberry-host>/plugins/hueapiv2/index.php?ajax=1&action=scene_command&bridge_id=<bridge-id>&scene_id=<scene-rid>&target_rid=<room-id>&target_rtype=room&state=0
```

Das Plugin antwortet mit einer JSON-Struktur; ein erfolgreicher Aufruf liefert `{"ok": true}`.
Wenn du stattdessen den Admin-Pfad einsetzt und dieser durch Basic Auth geschützt ist,
integriert die Oberfläche auf Wunsch Benutzername und Passwort automatisch in die URL, z. B.:

```
http://loxberry:geheimespasswort@<loxberry-host>/admin/plugins/hueapiv2/index.php?ajax=1&action=scene_command&bridge_id=<bridge-id>&scene_id=<scene-rid>&state=1
```

Beachte, dass Loxone die vollständige URL (inklusive Zugangsdaten) im Klartext speichert.

Zum Schalten einer Lampe steht derselbe Mechanismus bereit:

```
http://<loxberry-host>/plugins/hueapiv2/index.php?ajax=1&action=light_command&bridge_id=<bridge-id>&light_id=<light-rid>&on=1&brightness=75
```

Der Parameter `on` akzeptiert `1` (EIN) oder `0` (AUS); `brightness` (0–100) ist optional und
wird nur für das Einschalten berücksichtigt. Alternativ kannst du den Python-REST-Dienst weiter-
verwenden, wenn du lieber auf Port `5510` mit JSON arbeitest.

### Hue-Sensoren auf virtuelle Eingänge abbilden

Die Weboberfläche enthält den Abschnitt **„Hue → Loxone Eingänge“**, in dem du Hue-Schalter,
Dimmer oder Bewegungsmelder mit virtuellen Eingängen im Miniserver verknüpfst. Hinterlege dafür
die entsprechende Bridge, die Ressourcen-ID (z. B. aus der Ressourcenliste „Buttons“ oder
„Bewegungsmelder“) sowie den virtuellen Eingang, den Loxone schalten soll. Für Taster kannst du
einen Reset-Wert definieren, der nach einer kurzen Verzögerung gesendet wird – so entsteht ein
kurzer Impuls (`1` → `0`). Bewegungsmelder lösen den aktiven und optional den inaktiven Wert aus.

Sobald ein Eintrag gespeichert ist, lauscht das Plugin auf den Hue-Eventstream und sendet die
konfigurierten Werte automatisch an den angegebenen Miniserver (über die zuvor hinterlegte
Basis-URL). Damit kannst du Hue-Schalter oder Bewegungsmelder in Loxone-Logiken verwenden, ohne
dass zusätzliche Skripte nötig sind.

## Tests

Für zentrale Funktionen (z. B. das Laden der Konfiguration) existieren Unit-Tests, die
mit folgendem Befehl ausgeführt werden können:

```bash
pytest
```

## Lizenz

Dieses Projekt steht ohne Gewähr zur Verfügung. Passe den Code nach Bedarf für dein
konkretes Setup an.
