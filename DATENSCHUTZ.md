# Tegaru: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Optionen heißen `tegaru.*`; die Namen stehen im [README](README.md#configuration).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung und Formulierung müssen vor Veröffentlichung für den konkreten Betrieb fachlich geprüft und ergänzt werden. Der Text ist keine Rechtsberatung.

## Fotos über den Verwaltungsbereich — technischer Textbaustein

Fotos, die über den Verwaltungsbereich unserer Website hochgeladen werden, verkleinert Ihr Browser vor der Übertragung, sofern er das Foto verarbeiten kann. Dazu öffnet der Browser das Foto, dreht es entsprechend seiner Ausrichtung, verkleinert es auf höchstens 2400 Pixel an der längeren Seite (Standard; der Betreiber kann den Wert ändern) und speichert es neu im selben Format: JPEG und WebP mit Qualitätsstufe 0,85, PNG unverändert im Format. HEIC-Fotos verkleinert der Browser nicht; sie werden unverändert übertragen (siehe unten). Die neue Datei enthält keine Angaben aus der Kamera: keine GPS-Position, kein Kameramodell und keinen Aufnahmezeitpunkt. Das Original auf Ihrem Gerät bleibt unverändert. Übertragen wird dann die neue Datei. Ein Kamerafoto, das Ihr Telefon als `image.jpg` oder `image.jpeg` benennt, erhält beim Auswählen den Namen `foto-JJJJ-MM-TT-hh-mm-ss.jpg` mit der Ortszeit der Auswahl. Der Dateiname nennt dann nur Datum und Uhrzeit der Auswahl, keinen Ort und kein Gerät.

Die Verarbeitung findet im Browser auf Ihrem Gerät statt. Dabei wird kein anderer Dienst kontaktiert als unser eigener Server, der die Einstellungen für die Verkleinerung bereitstellt. Im Browser wird nichts gespeichert.

## Was der Server tut — technischer Textbaustein

Unser Server prüft die hochgeladene Datei auf Größe, Pixelzahl und Format; HEIC- und HEIF-Dateien werden abgelehnt. Die Prüfung gilt auch beim Ersetzen einer Datei. Tegaru selbst verändert die Datei auf dem Server nicht. Gespeichert wird sie wie jede andere Datei des Verwaltungsbereichs in unserem eigenen Speicher. Tegaru legt keine weiteren Daten an, und an der Verarbeitung ist kein Dienst eines Dritten beteiligt.

## Hinweise für den Betreiber

- **Ausnahmen.** Unverändert übertragen werden Fotos in diesen Fällen; alle Angaben der Datei bleiben dann erhalten, auch GPS-Positionen:
  - alle Fotos, solange `tegaru.active` nicht gesetzt ist,
  - Dateien unter `tegaru.minBytes` (Standard 1 MB; mit `0` verarbeitet der Browser jede Datei),
  - Dateien, die der Browser nicht als Bild öffnen kann, HEIC-Fotos eingeschlossen,
  - HEIC-Fotos sowie andere Formate wie GIF, AVIF, SVG und PDF,
  - JPEG-, PNG- oder WebP-Fotos, deren verkleinerte Fassung nicht kleiner ist als das Original oder deren Neuschreiben im Browser fehlschlägt,
  - WebP-Fotos, wenn der Browser WebP nicht schreiben kann,
  - alle Fotos, wenn die Einstellungen nicht abgerufen werden können oder dem Browser die nötigen Funktionen fehlen,
  - Fotos, die 20 Sekunden nach der Auswahl noch nicht fertig sind.

  Die Prüfung auf unserem Server gilt in allen diesen Fällen.
- **Am Browser vorbei.** Dateien, die nicht über den Verwaltungsbereich hochgeladen werden (Programmierschnittstelle, Import auf dem Server), gehen ohne Verkleinerung im Browser zum Server. Was im Verwaltungsbereich ausgewählt oder hineingezogen wird, auch aus der Dateien-App des Telefons oder vom Desktop, durchläuft dagegen die Verarbeitung im Browser. Setzt das Blueprint `create:`, verkleinert Kirby sie auf dem Server. Dabei entfernt Kirby die Metadaten nur mit dem GD-Treiber, dem Standard in Kirby 5: GD schreibt keine EXIF-Daten. Mit dem ImageMagick-Treiber ruft Kirby `-strip` nur bei PNG auf. JPEG-Dateien behalten dann ihren EXIF-Block einschließlich GPS-Position. Welcher Treiber eingestellt ist, steht in der Option `thumbs.driver`.
- **HEIC.** Tegaru wandelt HEIC-Fotos nicht um und entfernt sie nicht. Mit der Einschränkung des Blueprints auf JPEG, PNG und WebP (`accept`, siehe README) bietet der Auswahldialog HEIC-Dateien aus der Dateien-App nicht an. Fotos aus der Fotomediathek kommen dann als JPEG an, weil Safari sie selbst umwandelt. Das ist auf einem iPhone mit Safari geprüft worden, mit Android nicht. Dieses Foto war danach ohne EXIF- und GPS-Angaben (eine Messung). Eine HEIC-Datei, die doch in den Upload gelangt, geht unverändert zum Server und wird dort abgelehnt; sie wird nicht gespeichert.
- **Speicherung.** Gespeichert wird nur die Datei, die der Verwaltungsbereich ohnehin ablegt. Tegaru legt keine eigenen Dateien an.
- **Besucher.** Die Verarbeitung betrifft Uploads im Verwaltungsbereich. Für Besucher der Website ändert sich dadurch nichts.
