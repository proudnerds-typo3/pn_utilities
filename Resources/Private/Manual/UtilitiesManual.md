---
version: '1.0'
date: '2026-10-29'
---

# Tools

De module **Content > Tools** bevat hulpmiddelen voor het beheer van de website. Alleen
beheerders (admins) zien deze module. Er is op dit moment één tool: een lijst met
pagina's die lang niet zijn gewijzigd.

## Pagina's die lang niet zijn gewijzigd

Met deze tool spoor je pagina's op die al een half jaar niet zijn bijgewerkt en
misschien verouderde informatie bevatten.

### De lijst opvragen

1. Open de module **Content > Tools**.
2. Kies in de paginaboom de pagina vanaf waar je wilt zoeken. De tool bekijkt die
   pagina en alle pagina's eronder. Kies de bovenste pagina van de website om de hele
   website te bekijken.
3. Klik bij **Show pages not edited since 182 days** op **Show**.

De termijn staat vast op 182 dagen, ongeveer een half jaar.

### Welke pagina's in de lijst staan

- Alleen gewone pagina's (paginatype **Standard**). Mappen, snelkoppelingen, links
  naar andere websites en nieuwsberichten tellen niet mee.
- Verborgen pagina's tellen wel mee, verwijderde pagina's niet.
- Vertaalde pagina's staan als eigen regel in de lijst.
- Een pagina staat in de lijst als de paginaeigenschappen en de inhoud allebei 182
  dagen of langer niet zijn gewijzigd.

TYPO3 legt de laatste wijziging aan de inhoud vast wanneer de pagina op de website
wordt opgebouwd. Is de inhoud gewijzigd maar de pagina daarna nog niet bekeken, dan
kan de pagina ten onrechte in de lijst staan. Met **Update** werk je dat bij, zie
hieronder.

### Wat de lijst laat zien

| Kolom | Inhoud |
| --- | --- |
| PID | Het paginanummer (ID) van de bovenliggende pagina |
| Table | Altijd `pages` |
| UID | Het paginanummer (ID) van de pagina zelf |
| Edit | Opent de pagina in de module **Content > Layout** |
| Last change | De datum van de laatste wijziging |
| Page title | De titel. Een klik opent de pagina op de website, in een nieuw tabblad. |

Onder **Totals** staan twee aantallen:

- **Total no of pages found (incl. hidden, excl. deleted)**: hoeveel gewone pagina's
  de tool heeft bekeken.
- **Pages not edited since**: hoeveel daarvan in de lijst staan.

### De lijst bijwerken

Met de knop **Update - WARNING: HEAVY SERVER LOAD!** opent de tool elke pagina in de
lijst één keer op de website. TYPO3 legt daarbij de laatste wijziging aan de inhoud
opnieuw vast. Daarna maakt de tool de lijst opnieuw. Pagina's waarvan de inhoud toch
recent is gewijzigd, vallen er dan af.

- De knop werkt alleen als er 100 pagina's of minder in de lijst staan. Bij meer
  pagina's staat er **Update disabled (too many pages)**. Kies dan een pagina lager in
  de paginaboom, zodat de lijst korter wordt.
- Elke pagina opvragen belast de server. Gebruik **Update** daarom niet op drukke
  momenten.
- Verborgen pagina's kan de tool niet op de website openen. Die blijven in de lijst
  staan.

Met **Run again** maak je de lijst opnieuw zonder de pagina's op te vragen. Met
**Back to tools overview** ga je terug naar het overzicht van de tools.

### Wat je met de lijst doet

De lijst verandert zelf niets aan de pagina's. Loop de pagina's na en beslis per
pagina of de inhoud nog klopt. Een pagina verdwijnt uit de lijst zodra je een
wijziging in de paginaeigenschappen opslaat, of zodra je de inhoud wijzigt en de
pagina daarna op de website is bekeken.
