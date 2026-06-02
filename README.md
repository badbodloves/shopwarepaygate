# PayGate.to – Shopware 6.4 Plugin

Anonymes Payment-Gateway für Shopware 6.4 mit Sofort-Auszahlung in USDC (Polygon).
Akzeptiert Kreditkarte, Apple Pay, Google Pay, SEPA und ACH – ohne KYC, ohne Anmeldung.

---

## 1. Voraussetzungen

- Shopware 6.4 (PHP 7.4 oder höher; getestet mit PHP 8.0/8.1)
- Eine Polygon-Wallet, an die du USDC-Auszahlungen erhalten möchtest
  (z. B. MetaMask, Trust Wallet, Ledger – wichtig: Polygon-Mainnet-Adresse)
- Deine Shopware-Installation muss vom Internet erreichbar sein
  (für den PayGate-Callback). Lokale Dev-Umgebungen brauchen z. B. ngrok.

---

## 2. Plugin installieren

### Variante A: ZIP-Upload über das Backend

1. Lade das Plugin-Verzeichnis als ZIP herunter (Branch `claude/youthful-cerf-A0oaw`).
   Das Root-Verzeichnis im ZIP muss `PayGatePayment/` heißen.
   Beispiel:

   ```
   PayGatePayment/
   ├── composer.json
   └── src/
   ```

2. Im Shopware-Admin: **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen**
3. ZIP auswählen, hochladen.

### Variante B: Per Git/SSH auf den Server

```bash
cd <shopware-root>/custom/plugins
git clone -b claude/youthful-cerf-A0oaw <repo-url> PayGatePayment
```

Das Verzeichnis muss exakt `PayGatePayment` heißen (Plugin-Klassenname).

### Plugin aktivieren

Im Admin unter **Erweiterungen → Meine Erweiterungen** das Plugin
**„PayGate.to Zahlungsanbieter"** suchen und **Installieren** + **Aktivieren** klicken.

Alternativ per CLI:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PayGatePayment
bin/console cache:clear
```

---

## 3. Polygon-Wallet vorbereiten

Du brauchst eine **USDC (Polygon)**-Wallet-Adresse, an die alle Auszahlungen
gehen sollen. Wichtig:

- Es muss eine **Polygon-Mainnet**-Adresse sein (keine Ethereum-, BSC- oder
  andere Chain-Adresse).
- Adresse beginnt typischerweise mit `0x...` und ist 42 Zeichen lang.
- USDC-Token-Contract auf Polygon:
  `0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174`

Empfehlung: vorher einen Testbetrag (z. B. 1 USDC) an die Adresse senden
und prüfen, ob du das im Wallet siehst.

---

## 4. Plugin konfigurieren

Im Admin: **Erweiterungen → Meine Erweiterungen → PayGate.to → … → Konfiguration**

### Pflichtfeld

- **Merchant USDC (Polygon) Wallet-Adresse**: deine Polygon-Adresse von oben

### Optional

- **Affiliate Wallet-Adresse**: zweite Adresse, die bei jedem Verkauf
  Affiliate-Provision erhält (siehe PayGate-Doku)
- **Checkout-Modus** (entscheidend, siehe Abschnitt 5)
- **Bevorzugter Zahlungsanbieter** (nur Modus „Single")
- **Nicht-USD Währungen automatisch umrechnen**: empfohlen **AN**, weil
  Stripe/Transfi/Ramp/Bitnovo/Robinhood nur USD akzeptieren
- **Zahlungsstatus bei Rückkehr prüfen**: zusätzliche aktive Verifikation
  via `payment-status.php`. Normalerweise nicht nötig.

### White-Label (nur Multi-Provider-Modus)

- **Eigene Checkout-Domain**: ersetzt `checkout.paygate.to` durch deine
  Domain (Setup laut PayGate-White-Label-Guide nötig)
- **Logo-URL**, **Hintergrundfarbe**, **Theme-Farbe**, **Button-Farbe**:
  Branding für die gehostete `pay.php`-Seite

---

## 5. Checkout-Modus auswählen

Du hast drei Modi zur Auswahl:

### A) Single Provider (`process-payment.php`)

- Du wählst im Admin **einen festen Anbieter** (z. B. Stripe)
- Kunde geht direkt vom Shopware-Checkout zur Stripe-Bezahlseite
- Schnellster Flow, aber keine Auswahl für den Kunden
- USD-only-Provider lösen automatisch Währungs-Konvertierung aus

### B) Multi-Provider-Hosted (`pay.php`)

- Kunde landet auf der von PayGate gehosteten Auswahlseite
- White-Label-Felder (Domain, Logo, Farben) werden angewendet
- **Keine Sprachoption** – die Seite ist auf Englisch
- Empfohlen wenn du PayGate's gehostete UI nutzen willst

### C) Auswahlseite im Shop (deutsch, im Theme) – **empfohlen**

- Kunde landet auf einer eigenen Shopware-Seite (`/paygate/select-provider/...`)
- Vollständig auf Deutsch, im Look deines Themes
- Provider werden gruppiert dargestellt:
  - Kreditkarte & Wallets
  - Banküberweisung
  - Lokale Zahlungsmethoden
  - Weitere
- Mindestbeträge werden direkt angezeigt
- Nach Klick → Weiterleitung zum gewählten Provider via `process-payment.php`

---

## 6. Zahlungsmethode dem Verkaufskanal zuordnen

Das Plugin registriert beim Installieren automatisch eine neue Zahlungsmethode
**„PayGate.to (Kreditkarte / Apple Pay / SEPA)"**. Damit Kunden sie sehen,
musst du sie noch dem Verkaufskanal zuweisen:

1. **Verkaufskanäle → [dein Storefront]**
2. Reiter **Zahlung und Versand**
3. Unter **Zahlungsarten** die PayGate-Methode aktivieren (Häkchen setzen)
4. Speichern

Optional kannst du sie auch als **Standardzahlungsart** festlegen.

---

## 7. Testlauf

1. Im Storefront einen Testartikel in den Warenkorb legen
2. Zur Kasse gehen, **PayGate.to** als Zahlungsart wählen
3. Auf **Jetzt kostenpflichtig bestellen** klicken
4. Je nach Modus:
   - **Single**: direkter Redirect zum Provider (z. B. Stripe-Seite)
   - **Multi**: PayGate-Auswahlseite
   - **Picker**: deine deutsche Auswahlseite
5. Auf der Provider-Seite mit Testdaten zahlen
6. Nach erfolgreicher Zahlung:
   - PayGate sendet einen Callback an
     `https://deine-domain.de/paygate/callback/{transactionId}`
   - Bestellung wird automatisch auf **bezahlt** gesetzt
   - Du erhältst USDC auf deine Polygon-Wallet (typischerweise innerhalb
     weniger Minuten)

### Was im Hintergrund passiert

```
Shopware Checkout
   ↓ wallet.php (Callback-URL deiner Shopware-Domain übergeben)
PayGate API
   ↓ liefert verschlüsselte address_in + ipn_token
Redirect zu process-payment.php / pay.php / Picker-Seite
   ↓ Kunde zahlt
Provider (Stripe/MoonPay/…)
   ↓ schickt USDC an PayGate-Wallet
PayGate-Bot
   ↓ GET /paygate/callback/{transactionId}?value_coin=…&txid_out=…
Shopware CallbackController
   ↓ markiert Transaktion als „paid"
PayGate
   ↓ leitet USDC sofort an deine Merchant-Wallet weiter
```

---

## 8. Troubleshooting

### „Could not create PayGate.to payment wallet"

- Merchant-Wallet in der Konfiguration leer oder ungültig
- Shopware-Server kann `api.paygate.to` nicht erreichen (Firewall?)
- Log prüfen: `var/log/prod-*.log`

### Callback wird nicht aufgerufen / Bestellung bleibt „offen"

- Deine Shopware-Installation muss öffentlich erreichbar sein.
  Lokal: ngrok-Tunnel + `APP_URL` in `.env` setzen.
- HTTPS-Zertifikat muss gültig sein (PayGate verifiziert das)
- Prüfen: `GET https://deine-domain.de/paygate/callback/test` muss
  eine Response liefern (404 ist ok, Connection-Refused nicht)

### „Provider X requires USD but currency conversion failed"

- Im Admin **Nicht-USD Währungen automatisch umrechnen** aktivieren
- Oder: anderen Provider wählen, der deine Währung unterstützt

### Picker-Seite zeigt keine Provider

- `provider-status` ist nicht erreichbar – Server-Log prüfen
- Alle Provider gerade auf `status != active` – PayGate-Status prüfen

### Order wird nicht in Admin sichtbar als „bezahlt"

- Cache leeren: `bin/console cache:clear`
- Im Order-Backend → Reiter **Zahlung** → Status manuell prüfen
- Im Transaction-Custom-Field findest du `paygate_txid_in`/`paygate_txid_out`
  zur Blockchain-Verifikation

---

## 9. Custom Fields am Order Transaction

Das Plugin schreibt folgende Felder pro Transaction (sichtbar via API
oder per `getCustomFields()`):

| Feld | Bedeutung |
| --- | --- |
| `paygate_address_in` | Vom PayGate-API erzeugte verschlüsselte Wallet |
| `paygate_ipn_token` | Token für `payment-status.php` |
| `paygate_amount_original` | Originalbetrag in Shop-Währung |
| `paygate_currency_original` | Originalwährung (z. B. EUR) |
| `paygate_amount_sent` | Tatsächlich an PayGate gesendeter Betrag |
| `paygate_currency_sent` | Tatsächlich gesendete Währung (oft USD) |
| `paygate_chosen_provider` | Vom Kunden gewählter Provider (nur Picker) |
| `paygate_txid_in` | Polygon-TXID Provider → PayGate-Wallet |
| `paygate_txid_out` | Polygon-TXID PayGate → deine Merchant-Wallet |
| `paygate_value_coin` | USDC-Betrag den der Provider gesendet hat |
| `paygate_value_forwarded_coin` | An dich (und Affiliate) weitergeleiteter Betrag |
| `paygate_coin` | i.d.R. `polygon_usdc` |
| `paygate_paid_at` | Zeitpunkt des Callbacks (ISO 8601) |

---

## 10. Picker-Seite anpassen

Die deutsche Auswahlseite befindet sich unter:

```
src/Resources/views/storefront/page/paygate/select-provider.html.twig
```

Sie nutzt `sw_extends '@Storefront/storefront/base.html.twig'`, übernimmt
also automatisch dein Theme inkl. Header/Footer/CSS.

Provider-Gruppierung und -Namen sind als Konstante in
`src/Controller/ProviderSelectController.php` (Konstante `PROVIDER_GROUPS`)
hinterlegt – dort kannst du Gruppen umbenennen, neue Provider zuordnen
oder Beschreibungstexte anpassen.

Nach Änderungen:

```bash
bin/console cache:clear
bin/console theme:compile
```

---

## 11. Wichtige Hinweise

- **Mindestbestellwerte**: Jeder Provider hat ein Minimum
  (siehe https://paygate.to/instant-payment-gateway/#minimumorder).
  Liegt die Bestellung darunter, schlägt die Zahlung fehl. Die Picker-Seite
  zeigt das vorab an.
- **Kein iFrame**: PayGate verbietet das Einbetten in einen iFrame. Das
  Plugin nutzt korrekt einen Full-Page-Redirect.
- **Rückerstattungen**: Werden vom Plugin **nicht** automatisiert –
  müssen manuell on-chain (USDC-Rücksendung) abgewickelt werden.
- **DSGVO**: Bei der Zahlung wird die Kunden-E-Mail an PayGate
  übermittelt. Datenschutzerklärung entsprechend anpassen.

---

## Lizenz

MIT
