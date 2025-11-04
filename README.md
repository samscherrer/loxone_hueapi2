# LoxBerry Philips Hue API v2 Plugin

Dieses Projekt stellt einen Grundstein für ein LoxBerry Plugin bereit, das die Philips Hue
Bridge (API v2) anbindet und die wichtigsten Ressourcen für Loxone bereitstellt. Über
einen kleinen REST-Server lassen sich Lampen, Szenen und Räume abfragen sowie Aktionen
(z. B. das Aktivieren einer Szene oder das Schalten eines Lichts) auslösen.

## Funktionsumfang

* Abfrage von Hue-Lampen, -Szenen und -Räumen über die REST-Schnittstelle.
* Aktivieren von Szenen und Setzen grundlegender Lampen-Parameter (Ein/Aus, Helligkeit).
* Konfigurationsdatei und Umgebungsvariable zur einfachen Anpassung auf dem LoxBerry.

## Vorbereitung

1. Erzeuge bzw. erhalte einen **Application Key** für deine Hue Bridge über das
   offizielle Philips-Entwicklerportal.
2. Trage IP-Adresse und Schlüssel in `config/config.json` ein oder setze die
   Umgebungsvariable `HUE_PLUGIN_CONFIG` auf den Pfad einer eigenen Datei.
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
| GET     | `/lights`                    | Liste aller Lampen                     |
| POST    | `/lights/{id}/state`         | Licht schalten / dimmen                |
| GET     | `/scenes`                    | Liste aller Szenen                     |
| POST    | `/scenes/{id}/activate`      | Szene aktivieren                       |
| GET     | `/rooms`                     | Liste aller Räume (Areas/Zonen)        |

## Weboberfläche im LoxBerry

Nach der Installation erscheint das Plugin in der LoxBerry-Systemsteuerung. Beim
Aufruf wird automatisch die Datei `webfrontend/html/index.php` geladen, die die
grafische Oberfläche ausliefert. Dort kannst du

* die Verbindung zum lokalen REST-Dienst testen,
* Lampen, Räume und Szenen direkt auslesen,
* sowie einzelne Lampen oder Szenen zum Testen schalten.

Die Oberfläche erwartet, dass der REST-Dienst auf Port `5510` auf demselben LoxBerry
läuft. Falls du Port oder Hostname geändert hast, lässt sich dies über das Eingabefeld
"Basis-URL" anpassen.

## Plugin-Paket für LoxBerry

GitHub-Kompatibilität beschränkt die Bereitstellung fertiger ZIP-Archive in diesem
Repository. Um dennoch ein installierbares Paket zu erhalten, kannst du das Archiv
lokal selbst erzeugen. Der folgende Befehl erstellt eine ZIP-Datei mit dem korrekten
Verzeichnispräfix für LoxBerry:

```bash
git archive --format=zip --output "LoxBerry-Plugin-PhilipsHue-<version>.zip" \
  --prefix "LoxBerry-Plugin-PhilipsHue/" HEAD
```

Anschließend lässt sich die erzeugte Datei direkt über die LoxBerry-Weboberfläche
installieren.

Während der Installation sorgt `postroot.sh` dafür, dass ein virtuelles
Python-Umfeld angelegt und das Plugin darin installiert wird.

## Einbindung in Loxone

Innerhalb von Loxone kann dieser Dienst beispielsweise über HTTP-Kommandos angesteuert
werden. Durch die JSON-Antworten lassen sich die IDs der benötigten Lampen oder Szenen
ermitteln und anschließend gezielt schalten.

## Tests

Für zentrale Funktionen (z. B. das Laden der Konfiguration) existieren Unit-Tests, die
mit folgendem Befehl ausgeführt werden können:

```bash
pytest
```

## Lizenz

Dieses Projekt steht ohne Gewähr zur Verfügung. Passe den Code nach Bedarf für dein
konkretes Setup an.
