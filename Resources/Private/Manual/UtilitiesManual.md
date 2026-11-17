---
version: '2.0'
date: '2026-11-17'
---

# Tools

De module **Content > Tools** bevat hulpmiddelen voor het beheer van de website. Alleen
beheerders (admins) zien deze module. Er is op dit moment één tool: een lijst met
pagina's die lang niet zijn gewijzigd.

## Pagina's die lang niet zijn gewijzigd

Met deze tool spoor je pagina's op die al een tijd niet zijn bijgewerkt en misschien
verouderde informatie bevatten.

### De lijst opvragen

1. Open de module **Content > Tools**.
2. Kies in de paginaboom de pagina vanaf waar je wilt zoeken. De tool bekijkt die
   pagina en alle pagina's eronder. Kies de bovenste pagina van de website om de hele
   website te bekijken.
3. De lijst verschijnt direct, met een termijn van 182 dagen (ongeveer een half jaar).

Wil je een andere termijn, vul dan bij **Niet gewijzigd in ten minste (dagen)** een
aantal dagen in en klik op **Tonen**. Hoe korter de termijn, hoe langer de lijst.

### Welke pagina's in de lijst staan

- Alleen gewone pagina's (paginatype **Standard**). Mappen, snelkoppelingen en links
  naar andere websites tellen niet mee. Pagina's onder een map tellen wel mee.
- Verborgen pagina's tellen mee en hebben het label **Verborgen**. Verwijderde
  pagina's tellen niet mee.
- Vertaalde pagina's staan als eigen regel in de lijst. Voor een vertaling telt de
  inhoud in die taal en de inhoud die voor alle talen geldt.
- Een pagina staat in de lijst als de paginaeigenschappen en de inhoudselementen op
  de pagina allebei in de gekozen termijn niet zijn gewijzigd. Een verborgen
  inhoudselement telt ook als wijziging.

Records die vanuit een opslagmap op de pagina worden getoond, zoals nieuwsberichten,
tellen niet mee. Een nieuwsoverzicht kan dus in de lijst staan terwijl er wel nieuwe
berichten zijn.

### Wat de lijst laat zien

| Kolom | Inhoud |
| --- | --- |
| Paginatitel | De titel. Een klik opent de pagina op de website, in een nieuw tabblad. |
| Laatste wijziging | De datum van de laatste wijziging aan de pagina of haar inhoud |
| Pagina-ID | Het paginanummer (ID) van de pagina zelf |
| ID bovenliggende pagina | Het paginanummer (ID) van de bovenliggende pagina |
| Taal-ID | De taal van de regel. `0` is de standaardtaal. |
| Acties | Opent de pagina in de module **Content > Layout** |

Boven de tabel staat hoeveel pagina's in de gekozen termijn niet zijn gewijzigd.

### Wat je met de lijst doet

De lijst verandert zelf niets aan de pagina's. Loop de pagina's na en beslis per
pagina of de inhoud nog klopt. Een pagina verdwijnt uit de lijst zodra je de
paginaeigenschappen of een inhoudselement op de pagina wijzigt en opslaat.
