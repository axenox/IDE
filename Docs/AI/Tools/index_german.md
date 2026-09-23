# KI-Werkzeuge

[English](index.md)

## SQLSelectTool

Fuehrt eine einzelne, lesend ausgerichtete SQL-Abfrage aus und gibt das Ergebnis als Markdown-Tabelle
zurueck.

Typische Anwendungen sind das Pruefen von Tabellenzeilen, Datenaggregationen und das Lesen von
Datenbankmetadaten. Das Werkzeug ist nicht fuer Migrationen, Wartungsbefehle oder beabsichtigte
Datenveraenderungen geeignet.

### Konfiguration

- **Alias:** `axenox.IDE.SQLSelectTool`
- **UXON-Prototyp:** [SQLSelectTool.php](../../../AI/Tools/SQLSelectTool.php)
- **allow_multiple_queries:** Mit `true` werden mehrere durch Semikolon getrennte Abfragen erlaubt.
  Jede Abfrage wird einzeln validiert. Der Standardwert ist `false`.
- **statement:** Eine oder mehrere vollstaendige `SELECT`-Abfragen. Eine Abfrage darf mit `WITH`
  beginnen, wenn ihre abschliessende Operation ein `SELECT` ist.
- **data_connection_alias:** Optionaler Namespace-Alias der SQL-Datenverbindung. Der SQL-Admin-
  Assistent kann die Verbindung alternativ aus seinem Prompt beziehen.
- **explain:** Boolesches Werkzeugargument. Mit `true` werden das dialektspezifische EXPLAIN-Ergebnis
  von AdminNeo und, falls vom Datenbanktreiber unterstuetzt, tatsaechliche Laufzeit- und E/A-
  Statistiken fuer diesen Aufruf angehaengt. Der Standardwert ist `false`. Die Adminer-
  Integrationen geben leere Diagnoseergebnisse zurueck.

### Ergebnis

Die Ergebniszeilen werden als Markdown-Tabelle formatiert. Wenn mehrere Abfragen erlaubt sind,
werden sie einzeln ausgefuehrt und jede Ergebnistabelle unter einer nummerierten Ueberschrift
ausgegeben. Aktivierte EXPLAIN- und Laufzeitstatistik-Ausgaben werden unter dem jeweiligen
Abfrageergebnis angehaengt.

### Einschraenkungen des Nur-Lese-Zugriffs

Das Werkzeug weist standardmaessig mehrere Anweisungen zurueck. Andere Anweisungen als `SELECT`,
datenveraendernde Common Table Expressions, `SELECT INTO`, sperrende SELECT-Abfragen sowie gaengige
Schreib-, DDL- und Sitzungssteuerungs-Schluesselwoerter werden immer zurueckgewiesen. Vor der
Schluesselwortpruefung werden Zeichenketten und Kommentare lexikalisch verarbeitet, statt das rohe
SQL mit einem regulaeren Ausdruck zu validieren.

Diese Validierung verhindert gaengige versehentliche Schreibzugriffe, ist aber keine
Sicherheitsgrenze. SQL-Dialekte koennen Funktionen mit Seiteneffekten und andere Erweiterungen in
einem syntaktisch gueltigen `SELECT` bereitstellen. Wenn Nur-Lese-Zugriff garantiert werden muss, ist
ein Datenbankkonto zu verwenden, dessen Rechte ausschliesslich die vorgesehenen Leseoperationen
erlauben.

Das Voranstellen von `SELECT` vor beliebige Eingaben verbessert die Garantie nicht: Schreibende
Konstrukte koennen weiterhin spaeter in der Anweisung stehen, und gueltige `WITH`-Abfragen wuerden
beschaedigt.