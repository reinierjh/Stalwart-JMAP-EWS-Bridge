# Live EWS/JMAP-test

Voer deze test pas na review en eventuele deployment/staging uit op de bridge,
met **uitsluitend credentials van het apart aangemaakte testaccount**. Er worden
per objecttype maximaal vier synthetische items aangemaakt: mail wordt via
JMAP-upload/import geplaatst, niet verzonden; agenda-items hebben geen
deelnemers of uitnodigingen. De test wijzigt en verwijdert alleen zelf
vastgelegde IDs. Gebruik geen account met echte gegevens en voorkom gelijktijdige
wijzigingen door andere clients. De test verlangt één inbox, adresboek en agenda
en dezelfde primaire account voor de drie JMAP-capabilities.

Voorbeeld (vervang uitsluitend de placeholders; zet nooit credentials in de
commandoregel):

```sh
sudo python3 tests/live_ews.py \
  --jmap-session 'https://JMAP-HOST/jmap/session' \
  --ews-url 'http://127.0.0.1/EWS/Exchange.asmx' \
  --ews-host 'EWS-VIRTUAL-HOST' \
  --curl-config /root/.config/ews-bridge-test/curl.conf \
  --confirm-test-account
```

De standaard voor `--curl-config` is het hierboven getoonde pad. Het bestand
moet root-eigendom zijn, zonder groeps-/overige toegangsrechten, en uitsluitend
één curl `user`-optie bevatten. Het blijft intact. De test gebruikt curl zonder
shell, zonder redirects en zonder proxy; TLS-certificaatverificatie blijft aan.
Alle JMAP-URLs moeten HTTPS zijn; EWS moet localhost-HTTP zijn. De hostheader
is apart instelbaar. `--timeout` en `--max-pages` begrenzen uitvoering. De
bevestigingsoptie is verplicht, maar kan niet controleren of de credentials
daadwerkelijk van het testaccount zijn.

Per type maakt de test twee items, synchroniseert een baseline en controleert
de `ItemId` uit sync via GetItem, inclusief velden en ChangeKey. Daarna maakt
hij twee items, vraagt pagina's met `MaxChangesReturned=1`, herhaalt het eerste
verzoek met dezelfde token en shape, wijzigt en verwijdert een baseline-item
tussen pagina's en controleert continuation en follow-up op create/update/delete
zonder dubbele changes. Mailbody en agendabody worden gecontroleerd, evenals
contactnaam, agendatijden en ChangeKey. Een niet-werkende baseline of paging
kan afhankelijke checks voor dat type overslaan; andere typen worden daarna
wel geprobeerd. Exitstatus 0 betekent alle uitgevoerde checks en cleanup
geslaagd; 1 betekent fout, onderbreking of onbekende create-uitkomst. Output
bevat uitsluitend gesaneerde statussen, geen IDs, inhoud of credentials.

Cleanup draait ook na normale fouten, SIGINT en SIGTERM en verifieert elk
vastgelegd ID via JMAP `get`/`notFound`. SIGKILL, proces-/hostuitval en een
verloren create-response kunnen cleanup niet garanderen: bij een onbekende
uitkomst meldt de test dat handmatige reconciliatie nodig is. Controleer dan
uitsluitend het testaccount en verwijder alleen met zekerheid geïdentificeerde
synthetische items. Mail-upload kan een tijdelijke, niet aan een item gekoppelde
blob achterlaten; de server beheert de levensduur daarvan. Geen tijdelijke
testscriptbestanden of credentials worden aangemaakt of verwijderd.
