---
version: '2.2'
date: '2026-11-25'
---

# Tools

Deze extensie bevat twee hulpmiddelen:

- **Content > Tools**, met een lijst van pagina's die lang niet zijn gewijzigd. Alleen
  beheerders (admins) zien deze module.
- **Administration > Backend-gebruikers**, waarmee gebruikers zonder adminrechten andere
  backend-gebruikers kunnen beheren.

Per installatie bepaal je welke hulpmiddelen beschikbaar zijn, in **Admin > Settings >
Extension Configuration > pn_utilities**. De lijst met pagina's staat standaard aan,
gebruikersbeheer standaard uit. Werk na het aanzetten van gebruikersbeheer het
databaseschema bij, met **Analyze Database Structure** in de Install Tool of met
`typo3 database:updateschema`.

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

Boven de tabel staat hoeveel pagina's in de gekozen termijn niet zijn gewijzigd. De tabel
toont 100 regels tegelijk; met **Vorige** en **Volgende** onder de tabel blader je verder.

### Wat je met de lijst doet

De lijst verandert zelf niets aan de pagina's. Loop de pagina's na en beslis per
pagina of de inhoud nog klopt. Een pagina verdwijnt uit de lijst zodra je de
paginaeigenschappen of een inhoudselement op de pagina wijzigt en opslaat.

## Backend-gebruikers beheren

Met de module **Administration > Backend-gebruikers** maak je backend-gebruikers aan,
pas je ze aan, schakel je ze uit en verwijder je ze. Je hebt er geen adminrechten voor
nodig.

### Instellen (door een beheerder)

1. Zet gebruikersbeheer aan in de extensieconfiguratie (zie boven) en werk het
   databaseschema bij.
2. Open de groepen die gebruikersbeheerders mogen uitdelen, bijvoorbeeld **Redacteur**.
   Zet op het tabblad **General** (Algemeen) het vinkje **Toewijsbaar in gebruikersbeheer** aan.
   Wie zo'n groep krijgt, krijgt alle rechten van die groep en van haar subgroepen.
3. Geef de groep van de gebruikersbeheerders toegang tot de module
   **Backend-gebruikers** (tabblad **Module Permissions**). Markeer die groep zelf niet als
   toewijsbaar, tenzij gebruikersbeheerders andere gebruikersbeheerders mogen aanmaken.
4. Controleer dat de installatie e-mail kan versturen. Nieuwe gebruikers stellen hun
   wachtwoord in via een link in hun mailbox.

### Welke gebruikers je ziet

Je ziet alleen gebruikers van wie **alle** groepen toewijsbaar zijn. Beheerders (admins),
gebruikers zonder groep, gebruikers met een groep die niet toewijsbaar is en je eigen
account staan niet in de lijst en kun je niet aanpassen. Dat geldt ook voor gebruikers
die naast hun groepen eigen rechten hebben, zoals een eigen startpunt in de paginaboom,
eigen bestandsmappen, modules, talen of TSconfig.

### Een gebruiker aanmaken

1. Klik op **Nieuwe gebruiker**.
2. Vul een gebruikersnaam, naam en e-mailadres in en kies een of meer groepen. Elk
   e-mailadres kan maar bij één gebruiker horen.
3. Klik op **Opslaan**. De nieuwe gebruiker krijgt een e-mail met een link om zelf een
   wachtwoord in te stellen. Die link is twee uur geldig.

Is de link verlopen, klik dan in de lijst op de envelop om een nieuwe link te sturen.

### Een gebruiker aanpassen, uitschakelen of verwijderen

In de kolom **Acties** staan per gebruiker:

| Knop | Wat hij doet |
| --- | --- |
| Potlood | Naam en groepen aanpassen. Gebruikersnaam en e-mailadres blijven gelijk; een ander e-mailadres stelt een beheerder in. |
| Schakelaar | De gebruiker uit- of inschakelen. Een uitgeschakelde gebruiker kan niet inloggen. |
| Envelop | Een nieuwe link sturen om een wachtwoord in te stellen |
| Prullenbak | De gebruiker verwijderen, na een bevestiging |

Alle wijzigingen komen in het logboek van TYPO3 (module **Log**), met de naam van wie
ze heeft gedaan.
