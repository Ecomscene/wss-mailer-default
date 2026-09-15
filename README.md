# WSS Mailer (wss-mailer-default)

De nieuwsbrieftool van Webshopschool als losse plugin, voor webshops die geen
maandklant zijn. Zij rekenen per verstuurde mail af: EUR 0,50 per 1000 mails,
vooruit gekocht als tegoed.

## Hoe het in elkaar zit

```
wss-mailer-default.php        hoofdbestand: constanten, cron, updater
includes/
  class-wsmd-koppeling.php    praten met api-chat.webshopschool.nl
  class-wsmd-tegoed.php       het tegoed, het afboekpunt en kopen
  class-wsmd-scherm.php       het scherm "Mails en tegoed"
  class-wsmd-mailer.php       de mailer inladen
  class-wsmd-updater.php      bijwerken via GitHub-releases
mailer/                       KOPIE van mailer/ in wss-ai, ongewijzigd
```

## De regel voor de map mailer/

Die map is een kopie van dezelfde map in [wss-ai](https://github.com/Ecomscene/wss-ai)
en hoort daar regel voor regel gelijk aan te blijven. Verandert er iets aan de
mailer, dan gaat dat op beide plekken tegelijk.

De betaling zit er daarom **niet** in verwerkt. Hij hangt met twee filters aan
de buitenkant:

| Filter | Waar in de mailer | Wat er meekomt |
| --- | --- | --- |
| `wsfm_mag_versturen` | `WSFM_Newsletters::verstuur()`, na het bepalen van de ontvangers en voor de wachtrij | `soort` nieuwsbrief, `aantal` ontvangers, `ref` nieuwsbrief-id |
| `wsfm_mag_versturen` | `WSFM_Queue_Processor`, per mail, voor het versturen | `soort` flow, `aantal` 1, `ref` wachtrij-id |

In wss-ai luistert er niets naar die filters, dus daar verandert er niets. De
release-workflow weigert een release waarin `WSMD_` in de map `mailer/` staat.

## Waar het geld staat

Op de server, niet in de database van de winkel. De plugin toont wat de server
zegt en vraagt om af te boeken voordat er post uitgaat. Er zit geen
Stripe-sleutel in deze plugin en die hoort er nooit in te komen.

De server levert:

- `POST /api/portal/plugin/registreer` met `betaalt` (bool) en `tegoed` (int) in het antwoord
- `GET /api/portal/plugin/tegoed` met `betaalt`, `tegoed`, `prijsPer1000`, `bundels[]`
- `POST /api/portal/plugin/tegoed/reserveren` met `aantal`, `soort`, `ref`, idempotent op `ref`, en een 402 met `tekort` als er te weinig staat
- `POST /api/portal/plugin/tegoed/kopen` met `mails` en `terug`, antwoord `betaalUrl`
- de Stripe-webhook die bijboekt en de factuur mailt
- de schakelaar maandklant ja/nee in het pluginbeheer, die `betaalt` bepaalt

## Uitbrengen

Versienummer op drie plekken tegelijk: de kop van `wss-mailer-default.php`, de
constante `WSMD_VERSIE` en `Stable tag` in `readme.txt`. Daarna een tag `vX.Y.Z`
pushen; de workflow bouwt de zip en maakt de release. Blijft er een nummer
achter, dan weigert GitHub de release, en dat is de bedoeling.
