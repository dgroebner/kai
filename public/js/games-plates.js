/**
 * games-plates.js - KfZ-Kennzeichen-Raten & Live-Lexikon für Kai
 *
 * Beinhaltet:
 * 1. Vollständiges Verzeichnis aller 733 deutschen Unterscheidungszeichen
 * 2. Schnelle Live-Suche nach Kennzeichen, Ort, Herleitung oder Bundesland
 * 3. Kennzeichen-Quiz (Kennzeichen -> Ort, Ort -> Kennzeichen, Aufdeck-Modus)
 * 4. Single-Player Spielstand mit Vorname-Vorbelegung des Nutzers, Streak & Haptik
 *
 * CSP-konform (keine Inline-Handler, reine Event-Delegation).
 */
(function () {
    'use strict';

    // Bundesland-Schlüssel
    const STATES = {
        BW: 'Baden-Württemberg',
        BY: 'Bayern',
        BE: 'Berlin',
        BB: 'Brandenburg',
        HB: 'Bremen',
        HH: 'Hamburg',
        HE: 'Hessen',
        MV: 'Mecklenburg-Vorpommern',
        NI: 'Niedersachsen',
        NW: 'Nordrhein-Westfalen',
        RP: 'Rheinland-Pfalz',
        SL: 'Saarland',
        SN: 'Sachsen',
        ST: 'Sachsen-Anhalt',
        SH: 'Schleswig-Holstein',
        TH: 'Thüringen',
        BUND: 'Bundesweit / Bund'
    };

    const PLATES_DATA = [
  {
    "c": "A",
    "d": "Stadt Augsburg",
    "h": "Augsburg",
    "s": "BY",
    "p": 1
  },
  {
    "c": "AA",
    "d": "Ostalbkreis",
    "h": "AAlen",
    "s": "BW"
  },
  {
    "c": "AB",
    "d": "Stadt Aschaffenburg",
    "h": "AschaffenBurg",
    "s": "BY"
  },
  {
    "c": "ABG",
    "d": "Landkreis Altenburger Land",
    "h": "AltenBurG",
    "s": "TH",
    "p": 1
  },
  {
    "c": "ABI",
    "d": "Landkreis Anhalt-Bitterfeld",
    "h": "Anhalt-BItterfeld",
    "s": "ST"
  },
  {
    "c": "AC",
    "d": "Städteregion Aachen",
    "h": "AaChen / Aix la Chapelle",
    "s": "NW"
  },
  {
    "c": "AE",
    "d": "Vogtlandkreis",
    "h": "AuErbach",
    "s": "SN"
  },
  {
    "c": "AH",
    "d": "Kreis Borken",
    "h": "AHaus",
    "s": "NW"
  },
  {
    "c": "AIB",
    "d": "Landkreis München",
    "h": "AIBling",
    "s": "BY"
  },
  {
    "c": "AIC",
    "d": "Landkreis Aichach-Friedberg",
    "h": "AIChach",
    "s": "BY"
  },
  {
    "c": "AK",
    "d": "Landkreis Altenkirchen (Westerwald)",
    "h": "AltenKirchen",
    "s": "RP"
  },
  {
    "c": "ALF",
    "d": "Landkreis Hildesheim",
    "h": "ALFeld",
    "s": "NI"
  },
  {
    "c": "ALZ",
    "d": "Landkreis Aschaffenburg",
    "h": "ALZenau",
    "s": "BY"
  },
  {
    "c": "AM",
    "d": "Stadt Amberg",
    "h": "AMberg",
    "s": "BY"
  },
  {
    "c": "AN",
    "d": "Stadt Ansbach",
    "h": "ANsbach",
    "s": "BY"
  },
  {
    "c": "ANA",
    "d": "Erzgebirgskreis",
    "h": "ANnAberg",
    "s": "SN",
    "p": 1
  },
  {
    "c": "ANG",
    "d": "Landkreis Uckermark",
    "h": "ANGermünde",
    "s": "BB"
  },
  {
    "c": "ANK",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "ANKlam",
    "s": "MV"
  },
  {
    "c": "AÖ",
    "d": "Landkreis Altötting",
    "h": "AltÖtting",
    "s": "BY"
  },
  {
    "c": "AP",
    "d": "Landkreis Weimarer Land",
    "h": "APolda",
    "s": "TH"
  },
  {
    "c": "APD",
    "d": "Landkreis Weimarer Land",
    "h": "APolDa",
    "s": "TH"
  },
  {
    "c": "ARN",
    "d": "Ilm-Kreis",
    "h": "ARNstadt",
    "s": "TH"
  },
  {
    "c": "ART",
    "d": "Kyffhäuserkreis",
    "h": "ARTern",
    "s": "TH"
  },
  {
    "c": "AS",
    "d": "Landkreis Amberg-Sulzbach",
    "h": "Amberg, Sulzbach",
    "s": "BY"
  },
  {
    "c": "ASL",
    "d": "Salzlandkreis",
    "h": "ASchersLeben",
    "s": "ST"
  },
  {
    "c": "ASZ",
    "d": "Erzgebirgskreis",
    "h": "Aue, SchwarZenberg",
    "s": "SN"
  },
  {
    "c": "AT",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "AltenTreptow",
    "s": "MV"
  },
  {
    "c": "AU",
    "d": "Erzgebirgskreis",
    "h": "AUe",
    "s": "SN"
  },
  {
    "c": "AUR",
    "d": "Landkreis Aurich",
    "h": "AURich",
    "s": "NI"
  },
  {
    "c": "AW",
    "d": "Landkreis Ahrweiler",
    "h": "AhrWeiler",
    "s": "RP"
  },
  {
    "c": "AZ",
    "d": "Landkreis Alzey-Worms",
    "h": "AlZey",
    "s": "RP"
  },
  {
    "c": "AZE",
    "d": "Landkreis Anhalt-Bitterfeld",
    "h": "Anhalt-ZErbst",
    "s": "ST"
  },
  {
    "c": "B",
    "d": "Stadt Berlin",
    "h": "Berlin",
    "s": "BE",
    "p": 1
  },
  {
    "c": "BA",
    "d": "Stadt Bamberg",
    "h": "BAmberg",
    "s": "BY",
    "p": 1
  },
  {
    "c": "BAD",
    "d": "Stadt Baden-Baden",
    "h": "BADen-Baden",
    "s": "BW"
  },
  {
    "c": "BAR",
    "d": "Landkreis Barnim",
    "h": "BARnim",
    "s": "BB",
    "p": 1
  },
  {
    "c": "BB",
    "d": "Landkreis Böblingen",
    "h": "BöBlingen",
    "s": "BW"
  },
  {
    "c": "BBG",
    "d": "Salzlandkreis",
    "h": "BernBurG",
    "s": "ST"
  },
  {
    "c": "BBL",
    "d": "Brandenburg, Landesregierung, Landtag und Polizei",
    "h": "BrandenBurgische Landesregierung",
    "s": "BB"
  },
  {
    "c": "BC",
    "d": "Landkreis Biberach",
    "h": "BiberaCh",
    "s": "BW"
  },
  {
    "c": "BCH",
    "d": "Neckar-Odenwald-Kreis",
    "h": "BuCHen",
    "s": "BW"
  },
  {
    "c": "BD",
    "d": "Deutscher Bundestag, Bundesrat, Bundespräsidialamt, Bundesregierung, Bundesministerien, Bundesfinanzverwaltung (z. B. Zoll) und Bundesverfassungsgericht",
    "h": "BundesDienst",
    "s": "BUND"
  },
  {
    "c": "BE",
    "d": "Kreis Warendorf",
    "h": "BEckum",
    "s": "NW"
  },
  {
    "c": "BED",
    "d": "Landkreis Mittelsachsen",
    "h": "Brand-ErbisDorf",
    "s": "SN"
  },
  {
    "c": "BEL",
    "d": "Landkreis Potsdam-Mittelmark",
    "h": "BELzig",
    "s": "BB"
  },
  {
    "c": "BER",
    "d": "Landkreis Barnim",
    "h": "BERnau",
    "s": "BB"
  },
  {
    "c": "BF",
    "d": "Kreis Steinfurt",
    "h": "BurgsteinFurt",
    "s": "NW"
  },
  {
    "c": "BGD",
    "d": "Landkreis Berchtesgadener Land",
    "h": "BerchtesGaDen",
    "s": "BY"
  },
  {
    "c": "BGL",
    "d": "Landkreis Berchtesgadener Land",
    "h": "BerchtesGadener Land",
    "s": "BY"
  },
  {
    "c": "BH",
    "d": "Ortenaukreis",
    "h": "BüHl",
    "s": "BW"
  },
  {
    "c": "BI",
    "d": "Stadt Bielefeld",
    "h": "BIelefeld",
    "s": "NW",
    "p": 1
  },
  {
    "c": "BID",
    "d": "Landkreis Marburg-Biedenkopf",
    "h": "BIeDenkopf",
    "s": "HE"
  },
  {
    "c": "BIN",
    "d": "Landkreis Mainz-Bingen",
    "h": "BINgen",
    "s": "RP"
  },
  {
    "c": "BIR",
    "d": "Landkreis Birkenfeld",
    "h": "BIRkenfeld",
    "s": "RP"
  },
  {
    "c": "BIT",
    "d": "Eifelkreis Bitburg-Prüm",
    "h": "BITburg",
    "s": "RP"
  },
  {
    "c": "BIW",
    "d": "Landkreis Bautzen",
    "h": "BIschofsWerda",
    "s": "SN"
  },
  {
    "c": "BK",
    "d": "Rems-Murr-Kreis",
    "h": "BacKnang",
    "s": "BW"
  },
  {
    "c": "BKS",
    "d": "Landkreis Bernkastel-Wittlich",
    "h": "BernKaStel",
    "s": "RP"
  },
  {
    "c": "BL",
    "d": "Zollernalbkreis",
    "h": "BaLingen",
    "s": "BW"
  },
  {
    "c": "BLB",
    "d": "Kreis Siegen-Wittgenstein",
    "h": "BerLeBurg",
    "s": "NW"
  },
  {
    "c": "BLK",
    "d": "Burgenlandkreis",
    "h": "BurgenLandKreis",
    "s": "ST"
  },
  {
    "c": "BM",
    "d": "Rhein-Erft-Kreis",
    "h": "BergheiM",
    "s": "NW"
  },
  {
    "c": "BN",
    "d": "Stadt Bonn",
    "h": "BonN",
    "s": "NW",
    "p": 1
  },
  {
    "c": "BNA",
    "d": "Landkreis Leipzig",
    "h": "BorNA",
    "s": "SN"
  },
  {
    "c": "BO",
    "d": "Stadt Bochum",
    "h": "BOchum",
    "s": "NW",
    "p": 1
  },
  {
    "c": "BÖ",
    "d": "Landkreis Börde",
    "h": "BÖrde",
    "s": "ST"
  },
  {
    "c": "BOG",
    "d": "Landkreis Straubing-Bogen",
    "h": "BOGen",
    "s": "BY"
  },
  {
    "c": "BOH",
    "d": "Kreis Borken",
    "h": "BOcHolt",
    "s": "NW"
  },
  {
    "c": "BOR",
    "d": "Kreis Borken",
    "h": "BORken",
    "s": "NW"
  },
  {
    "c": "BOT",
    "d": "Stadt Bottrop",
    "h": "BOTtrop",
    "s": "NW"
  },
  {
    "c": "BP",
    "d": "Bundespolizei",
    "h": "BundesPolizei",
    "s": "BUND"
  },
  {
    "c": "BR",
    "d": "Landkreis Karlsruhe",
    "h": "BRuchsal",
    "s": "BW"
  },
  {
    "c": "BRA",
    "d": "Landkreis Wesermarsch",
    "h": "BRAke",
    "s": "NI"
  },
  {
    "c": "BRB",
    "d": "Stadt Brandenburg an der Havel",
    "h": "BRandenBurg",
    "s": "BB"
  },
  {
    "c": "BRG",
    "d": "Landkreis Jerichower Land",
    "h": "BuRG",
    "s": "ST"
  },
  {
    "c": "BRK",
    "d": "Landkreis Bad Kissingen",
    "h": "BRücKenau",
    "s": "BY"
  },
  {
    "c": "BRL",
    "d": "Landkreis Goslar",
    "h": "BRaunLage",
    "s": "NI"
  },
  {
    "c": "BRV",
    "d": "Landkreis Rotenburg (Wümme)",
    "h": "BRemerVörde",
    "s": "NI"
  },
  {
    "c": "BS",
    "d": "Stadt Braunschweig",
    "h": "BraunSchweig",
    "s": "NI"
  },
  {
    "c": "BSB",
    "d": "Landkreis Osnabrück",
    "h": "BerSenBrück",
    "s": "NI"
  },
  {
    "c": "BSK",
    "d": "Landkreis Oder-Spree",
    "h": "BeeSKow",
    "s": "BB"
  },
  {
    "c": "BT",
    "d": "Stadt Bayreuth",
    "h": "BayreuTh",
    "s": "BY"
  },
  {
    "c": "BTF",
    "d": "Landkreis Anhalt-Bitterfeld",
    "h": "BitTerFeld",
    "s": "ST"
  },
  {
    "c": "BÜD",
    "d": "Wetteraukreis",
    "h": "BÜDingen",
    "s": "HE"
  },
  {
    "c": "BUL",
    "d": "Landkreis Amberg-Sulzbach",
    "h": "BUrgLengenfeld",
    "s": "BY"
  },
  {
    "c": "BÜR",
    "d": "Kreis Paderborn",
    "h": "BÜRen",
    "s": "NW"
  },
  {
    "c": "BÜS",
    "d": "Gemeinde Büsingen am Hochrhein (Gemeinde im Landkreis Konstanz, deutsche Exklave in der Schweiz)",
    "h": "BÜSingen",
    "s": "BW"
  },
  {
    "c": "BÜZ",
    "d": "Landkreis Rostock",
    "h": "BÜtZow",
    "s": "MV"
  },
  {
    "c": "BW",
    "d": "Wasserstraßen- und Schifffahrtsverwaltung des Bundes",
    "h": "Bundes-Wasserstraßen- und Schifffahrtsverwaltung",
    "s": "BUND"
  },
  {
    "c": "BWL",
    "d": "Baden-Württemberg, Landesregierung, Landtag und Polizei",
    "h": "Baden-Württembergischer Landtag",
    "s": "BW"
  },
  {
    "c": "BYL",
    "d": "Bayern, Landesregierung und Landtag",
    "h": "BaYerischer Landtag",
    "s": "BY"
  },
  {
    "c": "BZ",
    "d": "Landkreis Bautzen",
    "h": "BautZen",
    "s": "SN",
    "p": 1
  },
  {
    "c": "C",
    "d": "Stadt Chemnitz",
    "h": "Chemnitz",
    "s": "SN",
    "p": 1
  },
  {
    "c": "CA",
    "d": "Landkreis Oberspreewald-Lausitz",
    "h": "CAlau",
    "s": "BB"
  },
  {
    "c": "CAS",
    "d": "Kreis Recklinghausen",
    "h": "CAStrop",
    "s": "NW"
  },
  {
    "c": "CB",
    "d": "Stadt Cottbus",
    "h": "CottBus",
    "s": "BB",
    "p": 1
  },
  {
    "c": "CE",
    "d": "Landkreis Celle",
    "h": "CElle",
    "s": "NI"
  },
  {
    "c": "CHA",
    "d": "Landkreis Cham",
    "h": "CHAm",
    "s": "BY"
  },
  {
    "c": "CLP",
    "d": "Landkreis Cloppenburg",
    "h": "CLopPenburg",
    "s": "NI"
  },
  {
    "c": "CLZ",
    "d": "Landkreis Goslar",
    "h": "CLausthal-Zellerfeld",
    "s": "NI"
  },
  {
    "c": "CO",
    "d": "Stadt Coburg und Landkreis Coburg (→ Zweckverband Zulassungsstelle Coburg)",
    "h": "COburg",
    "s": "BY"
  },
  {
    "c": "COC",
    "d": "Landkreis Cochem-Zell",
    "h": "COChem",
    "s": "RP"
  },
  {
    "c": "COE",
    "d": "Kreis Coesfeld",
    "h": "COEsfeld",
    "s": "NW"
  },
  {
    "c": "CR",
    "d": "Landkreis Schwäbisch Hall",
    "h": "CRailsheim",
    "s": "BW"
  },
  {
    "c": "CUX",
    "d": "Landkreis Cuxhaven",
    "h": "CUXhaven",
    "s": "NI"
  },
  {
    "c": "CW",
    "d": "Landkreis Calw",
    "h": "CalW",
    "s": "BW"
  },
  {
    "c": "D",
    "d": "Stadt Düsseldorf",
    "h": "Düsseldorf",
    "s": "NW",
    "p": 1
  },
  {
    "c": "DA",
    "d": "Landkreis Darmstadt-Dieburg und Stadt Darmstadt",
    "h": "DArmstadt",
    "s": "HE"
  },
  {
    "c": "DAH",
    "d": "Landkreis Dachau",
    "h": "DAcHau",
    "s": "BY"
  },
  {
    "c": "DAN",
    "d": "Landkreis Lüchow-Dannenberg",
    "h": "DANnenberg",
    "s": "NI"
  },
  {
    "c": "DAU",
    "d": "Landkreis Vulkaneifel",
    "h": "DAUn",
    "s": "RP"
  },
  {
    "c": "DBR",
    "d": "Landkreis Rostock",
    "h": "DoBeRan",
    "s": "MV"
  },
  {
    "c": "DD",
    "d": "Stadt Dresden",
    "h": "DresDen",
    "s": "SN",
    "p": 1
  },
  {
    "c": "DE",
    "d": "Stadt Dessau-Roßlau",
    "h": "DEssau",
    "s": "ST"
  },
  {
    "c": "DEG",
    "d": "Landkreis Deggendorf",
    "h": "DEGgendorf",
    "s": "BY"
  },
  {
    "c": "DEL",
    "d": "Stadt Delmenhorst",
    "h": "DELmenhorst",
    "s": "NI"
  },
  {
    "c": "DGF",
    "d": "Landkreis Dingolfing-Landau",
    "h": "DinGolFing",
    "s": "BY"
  },
  {
    "c": "DH",
    "d": "Landkreis Diepholz",
    "h": "DiepHolz",
    "s": "NI"
  },
  {
    "c": "DI",
    "d": "Landkreis Darmstadt-Dieburg",
    "h": "DIeburg",
    "s": "HE"
  },
  {
    "c": "DIL",
    "d": "Lahn-Dill-Kreis",
    "h": "DILlenburg",
    "s": "HE"
  },
  {
    "c": "DIN",
    "d": "Kreis Wesel",
    "h": "DINslaken",
    "s": "NW"
  },
  {
    "c": "DIZ",
    "d": "Rhein-Lahn-Kreis",
    "h": "DIeZ",
    "s": "RP"
  },
  {
    "c": "DKB",
    "d": "Landkreis Ansbach",
    "h": "DinKelsBühl",
    "s": "BY"
  },
  {
    "c": "DL",
    "d": "Landkreis Mittelsachsen",
    "h": "DöbeLn",
    "s": "SN"
  },
  {
    "c": "DLG",
    "d": "Landkreis Dillingen an der Donau",
    "h": "DilLinGen",
    "s": "BY"
  },
  {
    "c": "DM",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "DemMin",
    "s": "MV"
  },
  {
    "c": "DN",
    "d": "Kreis Düren",
    "h": "DüreN",
    "s": "NW"
  },
  {
    "c": "DO",
    "d": "Stadt Dortmund",
    "h": "DOrtmund",
    "s": "NW",
    "p": 1
  },
  {
    "c": "DON",
    "d": "Landkreis Donau-Ries",
    "h": "DONauwörth",
    "s": "BY"
  },
  {
    "c": "DS",
    "d": "Schwarzwald-Baar-Kreis",
    "h": "DonaueSchingen",
    "s": "BW"
  },
  {
    "c": "DT",
    "d": "Kreis Lippe",
    "h": "DeTmold",
    "s": "NW"
  },
  {
    "c": "DU",
    "d": "Stadt Duisburg",
    "h": "DUisburg",
    "s": "NW",
    "p": 1
  },
  {
    "c": "DUD",
    "d": "Landkreis Göttingen ohne die Stadt Göttingen",
    "h": "DUDerstadt",
    "s": "NI"
  },
  {
    "c": "DÜW",
    "d": "Landkreis Bad Dürkheim",
    "h": "DÜrkheim an der Weinstraße",
    "s": "RP"
  },
  {
    "c": "DW",
    "d": "Landkreis Sächsische Schweiz-Osterzgebirge",
    "h": "DippoldisWalde",
    "s": "SN"
  },
  {
    "c": "DZ",
    "d": "Landkreis Nordsachsen",
    "h": "DelitZsch",
    "s": "SN"
  },
  {
    "c": "E",
    "d": "Stadt Essen",
    "h": "Essen",
    "s": "NW",
    "p": 1
  },
  {
    "c": "EA",
    "d": "Wartburgkreis",
    "h": "EisenAch",
    "s": "TH"
  },
  {
    "c": "EB",
    "d": "Landkreis Nordsachsen",
    "h": "EilenBurg",
    "s": "SN"
  },
  {
    "c": "EBE",
    "d": "Landkreis Ebersberg",
    "h": "EBErsberg",
    "s": "BY"
  },
  {
    "c": "EBN",
    "d": "Landkreis Haßberge",
    "h": "EBerN",
    "s": "BY"
  },
  {
    "c": "EBS",
    "d": "Landkreis Bayreuth",
    "h": "EBermannStadt",
    "s": "BY"
  },
  {
    "c": "ECK",
    "d": "Kreis Rendsburg-Eckernförde",
    "h": "ECKernförde",
    "s": "SH"
  },
  {
    "c": "ED",
    "d": "Landkreis Erding",
    "h": "ErDing",
    "s": "BY"
  },
  {
    "c": "EE",
    "d": "Landkreis Elbe-Elster",
    "h": "Elbe, Elster",
    "s": "BB",
    "p": 1
  },
  {
    "c": "EF",
    "d": "Stadt Erfurt",
    "h": "ErFurt",
    "s": "TH",
    "p": 1
  },
  {
    "c": "EG",
    "d": "Landkreis Rottal-Inn",
    "h": "EGgenfelden",
    "s": "BY"
  },
  {
    "c": "EH",
    "d": "Landkreis Oder-Spree",
    "h": "EisenHüttenstadt",
    "s": "BB"
  },
  {
    "c": "EI",
    "d": "Landkreis Eichstätt",
    "h": "EIchstätt",
    "s": "BY"
  },
  {
    "c": "EIC",
    "d": "Landkreis Eichsfeld",
    "h": "EIChsfeld",
    "s": "TH"
  },
  {
    "c": "EIL",
    "d": "Landkreis Mansfeld-Südharz",
    "h": "EIsLeben",
    "s": "ST"
  },
  {
    "c": "EIN",
    "d": "Landkreis Northeim",
    "h": "EINbeck",
    "s": "NI"
  },
  {
    "c": "EIS",
    "d": "Saale-Holzland-Kreis",
    "h": "EISenberg",
    "s": "TH"
  },
  {
    "c": "EL",
    "d": "Landkreis Emsland",
    "h": "EmsLand",
    "s": "NI"
  },
  {
    "c": "EM",
    "d": "Landkreis Emmendingen",
    "h": "EMmendingen",
    "s": "BW"
  },
  {
    "c": "EMD",
    "d": "Stadt Emden",
    "h": "EMDen",
    "s": "NI"
  },
  {
    "c": "EMS",
    "d": "Rhein-Lahn-Kreis",
    "h": "EMS",
    "s": "RP"
  },
  {
    "c": "EN",
    "d": "Ennepe-Ruhr-Kreis",
    "h": "ENnepe",
    "s": "NW"
  },
  {
    "c": "ER",
    "d": "Stadt Erlangen",
    "h": "ERlangen",
    "s": "BY"
  },
  {
    "c": "ERB",
    "d": "Odenwaldkreis",
    "h": "ERBach",
    "s": "HE"
  },
  {
    "c": "ERH",
    "d": "Landkreis Erlangen-Höchstadt",
    "h": "ERlangen, Höchstadt",
    "s": "BY"
  },
  {
    "c": "ERK",
    "d": "Kreis Heinsberg",
    "h": "ERKelenz",
    "s": "NW"
  },
  {
    "c": "ERZ",
    "d": "Erzgebirgskreis",
    "h": "ERZgebirge",
    "s": "SN",
    "p": 1
  },
  {
    "c": "ES",
    "d": "Landkreis Esslingen",
    "h": "ESslingen",
    "s": "BW"
  },
  {
    "c": "ESB",
    "d": "Landkreis Amberg-Sulzbach",
    "h": "ESchenBach",
    "s": "BY"
  },
  {
    "c": "ESW",
    "d": "Werra-Meißner-Kreis",
    "h": "ESchWege",
    "s": "HE"
  },
  {
    "c": "EU",
    "d": "Kreis Euskirchen",
    "h": "EUskirchen",
    "s": "NW"
  },
  {
    "c": "EW",
    "d": "Landkreis Barnim",
    "h": "EbersWalde",
    "s": "BB"
  },
  {
    "c": "F",
    "d": "Stadt Frankfurt am Main",
    "h": "Frankfurt",
    "s": "HE",
    "p": 1
  },
  {
    "c": "FB",
    "d": "Wetteraukreis",
    "h": "FriedBerg",
    "s": "HE"
  },
  {
    "c": "FD",
    "d": "Landkreis Fulda",
    "h": "FulDa",
    "s": "HE",
    "p": 1
  },
  {
    "c": "FDB",
    "d": "Landkreis Aichach-Friedberg",
    "h": "FrieDBerg",
    "s": "BY"
  },
  {
    "c": "FDS",
    "d": "Landkreis Freudenstadt",
    "h": "FreuDenStadt",
    "s": "BW"
  },
  {
    "c": "FEU",
    "d": "Landkreis Ansbach",
    "h": "FEUchtwangen",
    "s": "BY"
  },
  {
    "c": "FF",
    "d": "Stadt Frankfurt (Oder)",
    "h": "FrankFurt",
    "s": "BB"
  },
  {
    "c": "FFB",
    "d": "Landkreis Fürstenfeldbruck",
    "h": "FürstenFeldBruck",
    "s": "BY"
  },
  {
    "c": "FG",
    "d": "Landkreis Mittelsachsen",
    "h": "FreiberG",
    "s": "SN",
    "p": 1
  },
  {
    "c": "FI",
    "d": "Landkreis Elbe-Elster",
    "h": "FInsterwalde",
    "s": "BB"
  },
  {
    "c": "FKB",
    "d": "Landkreis Waldeck-Frankenberg",
    "h": "FranKenBerg",
    "s": "HE"
  },
  {
    "c": "FL",
    "d": "Stadt Flensburg",
    "h": "FLensburg",
    "s": "SH",
    "p": 1
  },
  {
    "c": "FLÖ",
    "d": "Landkreis Mittelsachsen",
    "h": "FLÖha",
    "s": "SN"
  },
  {
    "c": "FN",
    "d": "Bodenseekreis",
    "h": "FriedrichshafeN",
    "s": "BW"
  },
  {
    "c": "FO",
    "d": "Landkreis Forchheim",
    "h": "FOrchheim",
    "s": "BY"
  },
  {
    "c": "FOR",
    "d": "Landkreis Spree-Neiße",
    "h": "FORst",
    "s": "BB"
  },
  {
    "c": "FR",
    "d": "Stadt Freiburg im Breisgau",
    "h": "FReiburg",
    "s": "BW",
    "p": 1
  },
  {
    "c": "FRG",
    "d": "Landkreis Freyung-Grafenau",
    "h": "FReyung, Grafenau",
    "s": "BY"
  },
  {
    "c": "FRI",
    "d": "Landkreis Friesland",
    "h": "FRIesland",
    "s": "NI"
  },
  {
    "c": "FRW",
    "d": "Landkreis Märkisch-Oderland",
    "h": "FReienWalde",
    "s": "BB"
  },
  {
    "c": "FS",
    "d": "Landkreis Freising",
    "h": "FreiSing",
    "s": "BY"
  },
  {
    "c": "FT",
    "d": "Stadt Frankenthal (Pfalz)",
    "h": "FrankenThal",
    "s": "RP"
  },
  {
    "c": "FTL",
    "d": "Landkreis Sächsische Schweiz-Osterzgebirge",
    "h": "FreiTaL",
    "s": "SN"
  },
  {
    "c": "FÜ",
    "d": "Stadt Fürth",
    "h": "FÜrth",
    "s": "BY"
  },
  {
    "c": "FÜS",
    "d": "Landkreis Ostallgäu",
    "h": "FÜSsen",
    "s": "BY"
  },
  {
    "c": "FW",
    "d": "Landkreis Oder-Spree",
    "h": "FürstenWalde",
    "s": "BB"
  },
  {
    "c": "FZ",
    "d": "Schwalm-Eder-Kreis",
    "h": "FritZlar",
    "s": "HE"
  },
  {
    "c": "G",
    "d": "Stadt Gera",
    "h": "Gera",
    "s": "TH",
    "p": 1
  },
  {
    "c": "GA",
    "d": "Altmarkkreis Salzwedel",
    "h": "GArdelegen",
    "s": "ST"
  },
  {
    "c": "GAN",
    "d": "Landkreis Northeim",
    "h": "GANdersheim",
    "s": "NI"
  },
  {
    "c": "GAP",
    "d": "Landkreis Garmisch-Partenkirchen",
    "h": "GArmisch-Partenkirchen",
    "s": "BY"
  },
  {
    "c": "GC",
    "d": "Landkreis Zwickau",
    "h": "GlauChau",
    "s": "SN",
    "p": 1
  },
  {
    "c": "GD",
    "d": "Ostalbkreis",
    "h": "GmünD",
    "s": "BW"
  },
  {
    "c": "GDB",
    "d": "Landkreis Nordwestmecklenburg ohne die Stadt Wismar",
    "h": "GaDeBusch",
    "s": "MV"
  },
  {
    "c": "GE",
    "d": "Stadt Gelsenkirchen",
    "h": "GElsenkirchen",
    "s": "NW",
    "p": 1
  },
  {
    "c": "GEL",
    "d": "Kreis Kleve",
    "h": "GELdern",
    "s": "NW"
  },
  {
    "c": "GEO",
    "d": "Landkreis Haßberge",
    "h": "GErOlzhofen",
    "s": "BY"
  },
  {
    "c": "GER",
    "d": "Landkreis Germersheim",
    "h": "GERmersheim",
    "s": "RP"
  },
  {
    "c": "GF",
    "d": "Landkreis Gifhorn",
    "h": "GiFhorn",
    "s": "NI"
  },
  {
    "c": "GG",
    "d": "Kreis Groß-Gerau",
    "h": "Groß-Gerau",
    "s": "HE"
  },
  {
    "c": "GHA",
    "d": "Landkreis Leipzig",
    "h": "GeitHAin",
    "s": "SN"
  },
  {
    "c": "GHC",
    "d": "Landkreis Wittenberg",
    "h": "GräfenHainiChen",
    "s": "ST"
  },
  {
    "c": "GI",
    "d": "Landkreis Gießen",
    "h": "GIeßen",
    "s": "HE",
    "p": 1
  },
  {
    "c": "GK",
    "d": "Kreis Heinsberg",
    "h": "GeilenKirchen",
    "s": "NW"
  },
  {
    "c": "GL",
    "d": "Rheinisch-Bergischer Kreis",
    "h": "GLadbach",
    "s": "NW"
  },
  {
    "c": "GLA",
    "d": "Kreis Recklinghausen",
    "h": "GLAdbeck",
    "s": "NW"
  },
  {
    "c": "GM",
    "d": "Oberbergischer Kreis",
    "h": "GumMersbach",
    "s": "NW"
  },
  {
    "c": "GMN",
    "d": "Landkreis Vorpommern-Rügen ohne die Stadt Stralsund",
    "h": "GrimMeN",
    "s": "MV"
  },
  {
    "c": "GN",
    "d": "Main-Kinzig-Kreis",
    "h": "GelnhauseN",
    "s": "HE"
  },
  {
    "c": "GNT",
    "d": "Landkreis Jerichower Land",
    "h": "GeNThin",
    "s": "ST"
  },
  {
    "c": "GÖ",
    "d": "Landkreis Göttingen Stadt Göttingen übriger Landkreis",
    "h": "GÖttingen",
    "s": "NI"
  },
  {
    "c": "GOA",
    "d": "Rhein-Hunsrück-Kreis",
    "h": "Sankt GOAr",
    "s": "RP"
  },
  {
    "c": "GOH",
    "d": "Rhein-Lahn-Kreis",
    "h": "Sankt GOarsHausen",
    "s": "RP"
  },
  {
    "c": "GP",
    "d": "Landkreis Göppingen",
    "h": "GöpPingen",
    "s": "BW"
  },
  {
    "c": "GR",
    "d": "Landkreis Görlitz",
    "h": "GöRlitz",
    "s": "SN",
    "p": 1
  },
  {
    "c": "GRA",
    "d": "Landkreis Freyung-Grafenau",
    "h": "GRAfenau",
    "s": "BY"
  },
  {
    "c": "GRH",
    "d": "Landkreis Meißen",
    "h": "GRoßenHain",
    "s": "SN"
  },
  {
    "c": "GRI",
    "d": "Landkreis Rottal-Inn",
    "h": "GRIesbach",
    "s": "BY"
  },
  {
    "c": "GRM",
    "d": "Landkreis Leipzig",
    "h": "GRimMa",
    "s": "SN"
  },
  {
    "c": "GRZ",
    "d": "Landkreis Greiz",
    "h": "GReiZ",
    "s": "TH"
  },
  {
    "c": "GS",
    "d": "Landkreis Goslar",
    "h": "GoSlar",
    "s": "NI"
  },
  {
    "c": "GT",
    "d": "Kreis Gütersloh",
    "h": "GüTersloh",
    "s": "NW"
  },
  {
    "c": "GTH",
    "d": "Landkreis Gotha",
    "h": "GoTHa",
    "s": "TH"
  },
  {
    "c": "GÜ",
    "d": "Landkreis Rostock",
    "h": "GÜstrow",
    "s": "MV"
  },
  {
    "c": "GUB",
    "d": "Landkreis Spree-Neiße",
    "h": "GUBen",
    "s": "BB"
  },
  {
    "c": "GUN",
    "d": "Landkreis Weißenburg-Gunzenhausen",
    "h": "GUNzenhausen",
    "s": "BY"
  },
  {
    "c": "GV",
    "d": "Rhein-Kreis Neuss",
    "h": "GreVenbroich",
    "s": "NW"
  },
  {
    "c": "GVM",
    "d": "Landkreis Nordwestmecklenburg ohne die Stadt Wismar",
    "h": "GreVesMühlen",
    "s": "MV"
  },
  {
    "c": "GW",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "GreifsWald",
    "s": "MV"
  },
  {
    "c": "GZ",
    "d": "Landkreis Günzburg",
    "h": "GünZburg",
    "s": "BY"
  },
  {
    "c": "H",
    "d": "Region Hannover Stadt Hannover übrige Region",
    "h": "Hannover",
    "s": "NI",
    "p": 1
  },
  {
    "c": "HA",
    "d": "Stadt Hagen",
    "h": "HAgen",
    "s": "NW"
  },
  {
    "c": "HAB",
    "d": "Landkreis Bad Kissingen",
    "h": "HAmmelBurg",
    "s": "BY"
  },
  {
    "c": "HAL",
    "d": "Stadt Halle (Saale)",
    "h": "HALle",
    "s": "ST",
    "p": 1
  },
  {
    "c": "HAM",
    "d": "Stadt Hamm",
    "h": "HAMm",
    "s": "NW"
  },
  {
    "c": "HAS",
    "d": "Landkreis Haßberge",
    "h": "HASsfurt",
    "s": "BY"
  },
  {
    "c": "HB",
    "d": "Stadt Bremen, Senat und Bürgerschaft",
    "h": "Hansestadt Bremen",
    "s": "HB",
    "p": 1
  },
  {
    "c": "HBN",
    "d": "Landkreis Hildburghausen",
    "h": "HildBurghauseN",
    "s": "TH"
  },
  {
    "c": "HBS",
    "d": "Landkreis Harz",
    "h": "HalBerStadt",
    "s": "ST"
  },
  {
    "c": "HC",
    "d": "Landkreis Mittelsachsen",
    "h": "HainiChen",
    "s": "SN"
  },
  {
    "c": "HCH",
    "d": "Landkreis Freudenstadt",
    "h": "HeCHingen",
    "s": "BW"
  },
  {
    "c": "HD",
    "d": "Stadt Heidelberg",
    "h": "HeiDelberg",
    "s": "BW"
  },
  {
    "c": "HDH",
    "d": "Landkreis Heidenheim",
    "h": "HeiDenHeim",
    "s": "BW"
  },
  {
    "c": "HDL",
    "d": "Landkreis Börde",
    "h": "HalDensLeben",
    "s": "ST"
  },
  {
    "c": "HE",
    "d": "Landkreis Helmstedt",
    "h": "HElmstedt",
    "s": "NI"
  },
  {
    "c": "HEB",
    "d": "Landkreis Nürnberger Land",
    "h": "HErsBruck",
    "s": "BY"
  },
  {
    "c": "HEF",
    "d": "Landkreis Hersfeld-Rotenburg",
    "h": "HErsFeld",
    "s": "HE"
  },
  {
    "c": "HEI",
    "d": "Kreis Dithmarschen",
    "h": "HEIde",
    "s": "SH"
  },
  {
    "c": "HEL",
    "d": "Hessen, Landesregierung und Landtag",
    "h": "HEssischer Landtag",
    "s": "HE"
  },
  {
    "c": "HER",
    "d": "Stadt Herne",
    "h": "HERne",
    "s": "NW"
  },
  {
    "c": "HET",
    "d": "Landkreis Mansfeld-Südharz",
    "h": "HETtstedt",
    "s": "ST"
  },
  {
    "c": "HF",
    "d": "Kreis Herford",
    "h": "HerFord",
    "s": "NW"
  },
  {
    "c": "HG",
    "d": "Hochtaunuskreis",
    "h": "HomburG",
    "s": "HE"
  },
  {
    "c": "HGN",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "HaGeNow",
    "s": "MV"
  },
  {
    "c": "HGW",
    "d": "Stadt Greifswald (große kreisangehörige Stadt im Landkreis Vorpommern-Greifswald)",
    "h": "Hansestadt GreifsWald",
    "s": "MV"
  },
  {
    "c": "HH",
    "d": "Freie und Hansestadt Hamburg, Senat und Bürgerschaft",
    "h": "Hansestadt Hamburg",
    "s": "HH",
    "p": 1
  },
  {
    "c": "HHM",
    "d": "Burgenlandkreis",
    "h": "HoHenMölsen",
    "s": "ST"
  },
  {
    "c": "HI",
    "d": "Landkreis Hildesheim",
    "h": "HIldesheim",
    "s": "NI"
  },
  {
    "c": "HIG",
    "d": "Landkreis Eichsfeld",
    "h": "HeilIGenstadt",
    "s": "TH"
  },
  {
    "c": "HIP",
    "d": "Landkreis Roth",
    "h": "HIlPoltstein",
    "s": "BY"
  },
  {
    "c": "HK",
    "d": "Landkreis Heidekreis",
    "h": "HeideKreis",
    "s": "NI"
  },
  {
    "c": "HL",
    "d": "Stadt Lübeck",
    "h": "Hansestadt Lübeck",
    "s": "SH",
    "p": 1
  },
  {
    "c": "HM",
    "d": "Landkreis Hameln-Pyrmont",
    "h": "HaMeln",
    "s": "NI"
  },
  {
    "c": "HMÜ",
    "d": "Landkreis Göttingen ohne die Stadt Göttingen",
    "h": "Hann. MÜnden",
    "s": "NI"
  },
  {
    "c": "HN",
    "d": "Stadt Heilbronn",
    "h": "HeilbronN",
    "s": "BW",
    "p": 1
  },
  {
    "c": "HO",
    "d": "Stadt Hof",
    "h": "HOf",
    "s": "BY"
  },
  {
    "c": "HOG",
    "d": "Landkreis Kassel",
    "h": "HOfGeismar",
    "s": "HE"
  },
  {
    "c": "HOH",
    "d": "Landkreis Haßberge",
    "h": "HOfHeim",
    "s": "BY"
  },
  {
    "c": "HOL",
    "d": "Landkreis Holzminden",
    "h": "HOLzminden",
    "s": "NI"
  },
  {
    "c": "HOM",
    "d": "Saarpfalz-Kreis ohne die Stadt St. Ingbert",
    "h": "HOMburg",
    "s": "SL"
  },
  {
    "c": "HOR",
    "d": "Landkreis Freudenstadt",
    "h": "HORb",
    "s": "BW"
  },
  {
    "c": "HÖS",
    "d": "Landkreis Erlangen-Höchstadt",
    "h": "HÖchStadt",
    "s": "BY"
  },
  {
    "c": "HOT",
    "d": "Landkreis Zwickau",
    "h": "HOhensTein",
    "s": "SN",
    "p": 1
  },
  {
    "c": "HP",
    "d": "Landkreis Bergstraße",
    "h": "HepPenheim",
    "s": "HE"
  },
  {
    "c": "HR",
    "d": "Schwalm-Eder-Kreis",
    "h": "HombeRg",
    "s": "HE"
  },
  {
    "c": "HRO",
    "d": "Stadt Rostock",
    "h": "Hansestadt ROstock",
    "s": "MV",
    "p": 1
  },
  {
    "c": "HS",
    "d": "Kreis Heinsberg",
    "h": "HeinSberg",
    "s": "NW"
  },
  {
    "c": "HSK",
    "d": "Hochsauerlandkreis",
    "h": "HochSauerlandKreis",
    "s": "NW"
  },
  {
    "c": "HST",
    "d": "Stadt Stralsund (große kreisangehörige Stadt im Landkreis Vorpommern-Rügen)",
    "h": "Hansestadt STralsund",
    "s": "MV"
  },
  {
    "c": "HU",
    "d": "Stadt Hanau",
    "h": "HanaU",
    "s": "HE"
  },
  {
    "c": "HV",
    "d": "Landkreis Stendal",
    "h": "HaVelberg",
    "s": "ST"
  },
  {
    "c": "HVL",
    "d": "Landkreis Havelland",
    "h": "HaVelLand",
    "s": "BB"
  },
  {
    "c": "HWI",
    "d": "Stadt Wismar (große kreisangehörige Stadt im Landkreis Nordwestmecklenburg)",
    "h": "Hansestadt WIsmar",
    "s": "MV"
  },
  {
    "c": "HX",
    "d": "Kreis Höxter",
    "h": "HöXter",
    "s": "NW"
  },
  {
    "c": "HY",
    "d": "Landkreis Bautzen",
    "h": "HoYerswerda",
    "s": "SN"
  },
  {
    "c": "HZ",
    "d": "Landkreis Harz",
    "h": "HarZ",
    "s": "ST"
  },
  {
    "c": "IGB",
    "d": "Stadt St. Ingbert (Mittelstadt im Saarpfalz-Kreis)",
    "h": "InGBert",
    "s": "SL"
  },
  {
    "c": "IK",
    "d": "Ilm-Kreis",
    "h": "Ilm-Kreis",
    "s": "TH"
  },
  {
    "c": "IL",
    "d": "Ilm-Kreis",
    "h": "ILmenau",
    "s": "TH"
  },
  {
    "c": "ILL",
    "d": "Landkreis Neu-Ulm",
    "h": "ILLertissen",
    "s": "BY"
  },
  {
    "c": "IN",
    "d": "Stadt Ingolstadt",
    "h": "INgolstadt",
    "s": "BY",
    "p": 1
  },
  {
    "c": "IZ",
    "d": "Kreis Steinburg",
    "h": "ItZehoe",
    "s": "SH"
  },
  {
    "c": "J",
    "d": "Stadt Jena",
    "h": "Jena",
    "s": "TH",
    "p": 1
  },
  {
    "c": "JB",
    "d": "Landkreis Teltow-Fläming",
    "h": "JüterBog",
    "s": "BB"
  },
  {
    "c": "JE",
    "d": "Landkreis Wittenberg",
    "h": "JEssen",
    "s": "ST"
  },
  {
    "c": "JL",
    "d": "Landkreis Jerichower Land",
    "h": "Jerichower Land",
    "s": "ST"
  },
  {
    "c": "JÜL",
    "d": "Kreis Düren",
    "h": "JÜLich",
    "s": "NW"
  },
  {
    "c": "K",
    "d": "Stadt Köln",
    "h": "Köln",
    "s": "NW",
    "p": 1
  },
  {
    "c": "KA",
    "d": "Stadt Karlsruhe",
    "h": "KArlsruhe",
    "s": "BW",
    "p": 1
  },
  {
    "c": "KB",
    "d": "Landkreis Waldeck-Frankenberg",
    "h": "KorBach",
    "s": "HE"
  },
  {
    "c": "KC",
    "d": "Landkreis Kronach",
    "h": "KronaCh",
    "s": "BY"
  },
  {
    "c": "KE",
    "d": "Stadt Kempten (Allgäu)",
    "h": "KEmpten",
    "s": "BY"
  },
  {
    "c": "KEH",
    "d": "Landkreis Kelheim",
    "h": "KElHeim",
    "s": "BY"
  },
  {
    "c": "KEL",
    "d": "Ortenaukreis",
    "h": "KEhL",
    "s": "BW"
  },
  {
    "c": "KEM",
    "d": "Landkreis Bayreuth",
    "h": "KEMnath",
    "s": "BY"
  },
  {
    "c": "KF",
    "d": "Stadt Kaufbeuren",
    "h": "KauFbeuren",
    "s": "BY"
  },
  {
    "c": "KG",
    "d": "Landkreis Bad Kissingen",
    "h": "KissinGen",
    "s": "BY"
  },
  {
    "c": "KH",
    "d": "Landkreis Bad Kreuznach",
    "h": "KreuznacH",
    "s": "RP"
  },
  {
    "c": "KI",
    "d": "Stadt Kiel",
    "h": "KIel",
    "s": "SH",
    "p": 1
  },
  {
    "c": "KIB",
    "d": "Donnersbergkreis",
    "h": "KIrchheimBolanden",
    "s": "RP"
  },
  {
    "c": "KK",
    "d": "Kreis Viersen",
    "h": "Kempen, Krefeld",
    "s": "NW"
  },
  {
    "c": "KL",
    "d": "Stadt Kaiserslautern",
    "h": "KaisersLautern",
    "s": "RP",
    "p": 1
  },
  {
    "c": "KLE",
    "d": "Kreis Kleve",
    "h": "KLEve",
    "s": "NW"
  },
  {
    "c": "KLZ",
    "d": "Altmarkkreis Salzwedel",
    "h": "KLötZe",
    "s": "ST"
  },
  {
    "c": "KM",
    "d": "Landkreis Bautzen",
    "h": "KaMenz",
    "s": "SN",
    "p": 1
  },
  {
    "c": "KN",
    "d": "Landkreis Konstanz ohne die Gemeinde Büsingen am Hochrhein",
    "h": "KoNstanz",
    "s": "BW"
  },
  {
    "c": "KO",
    "d": "Stadt Koblenz",
    "h": "KOblenz",
    "s": "RP",
    "p": 1
  },
  {
    "c": "KÖN",
    "d": "Landkreis Rhön-Grabfeld",
    "h": "KÖNigshofen",
    "s": "BY"
  },
  {
    "c": "KÖT",
    "d": "Landkreis Anhalt-Bitterfeld",
    "h": "KÖThen",
    "s": "ST"
  },
  {
    "c": "KÖZ",
    "d": "Landkreis Cham",
    "h": "KÖtZting",
    "s": "BY"
  },
  {
    "c": "KR",
    "d": "Stadt Krefeld",
    "h": "KRefeld",
    "s": "NW"
  },
  {
    "c": "KRU",
    "d": "Landkreis Günzburg",
    "h": "KRUmbach",
    "s": "BY"
  },
  {
    "c": "KS",
    "d": "Landkreis Kassel und Stadt Kassel",
    "h": "KasSel",
    "s": "HE",
    "p": 1
  },
  {
    "c": "KT",
    "d": "Landkreis Kitzingen",
    "h": "KiTzingen",
    "s": "BY"
  },
  {
    "c": "KU",
    "d": "Landkreis Kulmbach",
    "h": "KUlmbach",
    "s": "BY"
  },
  {
    "c": "KÜN",
    "d": "Hohenlohekreis",
    "h": "KÜNzelsau",
    "s": "BW"
  },
  {
    "c": "KUS",
    "d": "Landkreis Kusel",
    "h": "KUSel",
    "s": "RP"
  },
  {
    "c": "KW",
    "d": "Landkreis Dahme-Spreewald",
    "h": "Königs Wusterhausen",
    "s": "BB"
  },
  {
    "c": "KY",
    "d": "Landkreis Ostprignitz-Ruppin",
    "h": "KYritz",
    "s": "BB"
  },
  {
    "c": "KYF",
    "d": "Kyffhäuserkreis",
    "h": "KYFfhäuser",
    "s": "TH"
  },
  {
    "c": "L",
    "d": "Stadt Leipzig",
    "h": "Leipzig",
    "s": "SN",
    "p": 1
  },
  {
    "c": "LA",
    "d": "Stadt Landshut",
    "h": "LAndshut",
    "s": "BY"
  },
  {
    "c": "LAN",
    "d": "Landkreis Dingolfing-Landau",
    "h": "LANdau",
    "s": "BY"
  },
  {
    "c": "LAU",
    "d": "Landkreis Nürnberger Land",
    "h": "LAUf",
    "s": "BY"
  },
  {
    "c": "LB",
    "d": "Landkreis Ludwigsburg",
    "h": "LudwigsBurg",
    "s": "BW"
  },
  {
    "c": "LBS",
    "d": "Saale-Orla-Kreis",
    "h": "LoBenStein",
    "s": "TH"
  },
  {
    "c": "LBZ",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "LüBZ",
    "s": "MV"
  },
  {
    "c": "LC",
    "d": "Landkreis Dahme-Spreewald",
    "h": "LuCkau",
    "s": "BB"
  },
  {
    "c": "LD",
    "d": "Stadt Landau in der Pfalz",
    "h": "LanDau",
    "s": "RP"
  },
  {
    "c": "LDK",
    "d": "Lahn-Dill-Kreis",
    "h": "Lahn-Dill-Kreis",
    "s": "HE"
  },
  {
    "c": "LDS",
    "d": "Landkreis Dahme-Spreewald",
    "h": "Landkreis Dahme-Spreewald",
    "s": "BB",
    "p": 1
  },
  {
    "c": "LE",
    "d": "Kreis Lippe",
    "h": "LEmgo",
    "s": "NW"
  },
  {
    "c": "LEO",
    "d": "Landkreis Böblingen",
    "h": "LEOnberg",
    "s": "BW"
  },
  {
    "c": "LER",
    "d": "Landkreis Leer",
    "h": "LEeR",
    "s": "NI"
  },
  {
    "c": "LEV",
    "d": "Stadt Leverkusen",
    "h": "LEVerkusen",
    "s": "NW"
  },
  {
    "c": "LF",
    "d": "Landkreis Altötting",
    "h": "LauFen",
    "s": "BY"
  },
  {
    "c": "LG",
    "d": "Landkreis Lüneburg",
    "h": "LüneburG",
    "s": "NI"
  },
  {
    "c": "LH",
    "d": "Kreis Coesfeld",
    "h": "LüdingHausen",
    "s": "NW"
  },
  {
    "c": "LI",
    "d": "Landkreis Lindau (Bodensee)",
    "h": "LIndau",
    "s": "BY"
  },
  {
    "c": "LIB",
    "d": "Landkreis Elbe-Elster",
    "h": "LIeBenwerda",
    "s": "BB"
  },
  {
    "c": "LIF",
    "d": "Landkreis Lichtenfels",
    "h": "LIchtenFels",
    "s": "BY"
  },
  {
    "c": "LIP",
    "d": "Kreis Lippe",
    "h": "LIPpe",
    "s": "NW"
  },
  {
    "c": "LK",
    "d": "Kreis Minden-Lübbecke",
    "h": "LübbecKe",
    "s": "NW"
  },
  {
    "c": "LL",
    "d": "Landkreis Landsberg am Lech",
    "h": "Landsberg am Lech",
    "s": "BY"
  },
  {
    "c": "LM",
    "d": "Landkreis Limburg-Weilburg",
    "h": "LiMburg",
    "s": "HE"
  },
  {
    "c": "LN",
    "d": "Landkreis Dahme-Spreewald",
    "h": "LübbeN",
    "s": "BB"
  },
  {
    "c": "LÖ",
    "d": "Landkreis Lörrach",
    "h": "LÖrrach",
    "s": "BW"
  },
  {
    "c": "LÖB",
    "d": "Landkreis Görlitz",
    "h": "LÖBau",
    "s": "SN"
  },
  {
    "c": "LOS",
    "d": "Landkreis Oder-Spree",
    "h": "Landkreis Oder-Spree",
    "s": "BB",
    "p": 1
  },
  {
    "c": "LP",
    "d": "Kreis Soest",
    "h": "LiPpstadt",
    "s": "NW"
  },
  {
    "c": "LR",
    "d": "Ortenaukreis",
    "h": "LahR",
    "s": "BW"
  },
  {
    "c": "LRO",
    "d": "Landkreis Rostock",
    "h": "Landkreis ROstock",
    "s": "MV",
    "p": 1
  },
  {
    "c": "LSA",
    "d": "Sachsen-Anhalt, Landesregierung, Landtag und Polizei",
    "h": "Land Sachsen-Anhalt",
    "s": "ST"
  },
  {
    "c": "LSN",
    "d": "Sachsen, Landesregierung und Landtag",
    "h": "Landtag SachseN",
    "s": "SN"
  },
  {
    "c": "LSZ",
    "d": "Unstrut-Hainich-Kreis",
    "h": "LangenSalZa",
    "s": "TH"
  },
  {
    "c": "LU",
    "d": "Stadt Ludwigshafen am Rhein",
    "h": "LUdwigshafen",
    "s": "RP",
    "p": 1
  },
  {
    "c": "LUK",
    "d": "Landkreis Teltow-Fläming",
    "h": "LUcKenwalde",
    "s": "BB"
  },
  {
    "c": "LÜN",
    "d": "Kreis Unna",
    "h": "LÜNen",
    "s": "NW"
  },
  {
    "c": "LUP",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "LUdwigslust, Parchim",
    "s": "MV"
  },
  {
    "c": "LWL",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "LudWigsLust",
    "s": "MV"
  },
  {
    "c": "M",
    "d": "Stadt München",
    "h": "München",
    "s": "BY",
    "p": 1
  },
  {
    "c": "MA",
    "d": "Stadt Mannheim",
    "h": "MAnnheim",
    "s": "BW",
    "p": 1
  },
  {
    "c": "MAB",
    "d": "Erzgebirgskreis",
    "h": "MArienBerg",
    "s": "SN"
  },
  {
    "c": "MAI",
    "d": "Landkreis Kelheim",
    "h": "MAInburg",
    "s": "BY"
  },
  {
    "c": "MAK",
    "d": "Landkreis Wunsiedel im Fichtelgebirge",
    "h": "MArKtredwitz",
    "s": "BY"
  },
  {
    "c": "MAL",
    "d": "Landkreis Landshut",
    "h": "MALlersdorf",
    "s": "BY"
  },
  {
    "c": "MB",
    "d": "Landkreis Miesbach",
    "h": "MiesBach",
    "s": "BY"
  },
  {
    "c": "MC",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "MalChin",
    "s": "MV"
  },
  {
    "c": "MD",
    "d": "Stadt Magdeburg",
    "h": "MagDeburg",
    "s": "ST",
    "p": 1
  },
  {
    "c": "ME",
    "d": "Kreis Mettmann",
    "h": "MEttmann",
    "s": "NW"
  },
  {
    "c": "MED",
    "d": "Kreis Dithmarschen",
    "h": "MElDorf",
    "s": "SH"
  },
  {
    "c": "MEG",
    "d": "Schwalm-Eder-Kreis",
    "h": "MElsunGen",
    "s": "HE"
  },
  {
    "c": "MEI",
    "d": "Landkreis Meißen",
    "h": "MEIßen",
    "s": "SN",
    "p": 1
  },
  {
    "c": "MEK",
    "d": "Erzgebirgskreis",
    "h": "Mittlerer ErzgebirgsKreis",
    "s": "SN"
  },
  {
    "c": "MEL",
    "d": "Landkreis Osnabrück",
    "h": "MELle",
    "s": "NI"
  },
  {
    "c": "MER",
    "d": "Saalekreis",
    "h": "MERseburg",
    "s": "ST"
  },
  {
    "c": "MET",
    "d": "Landkreis Rhön-Grabfeld",
    "h": "MEllrichstadT",
    "s": "BY"
  },
  {
    "c": "MG",
    "d": "Stadt Mönchengladbach",
    "h": "MönchenGladbach",
    "s": "NW"
  },
  {
    "c": "MGH",
    "d": "Main-Tauber-Kreis",
    "h": "MerGentHeim",
    "s": "BW"
  },
  {
    "c": "MGN",
    "d": "Landkreis Schmalkalden-Meiningen",
    "h": "MeininGeN",
    "s": "TH"
  },
  {
    "c": "MH",
    "d": "Stadt Mülheim an der Ruhr",
    "h": "MülHeim",
    "s": "NW"
  },
  {
    "c": "MHL",
    "d": "Unstrut-Hainich-Kreis",
    "h": "MüHLhausen",
    "s": "TH"
  },
  {
    "c": "MI",
    "d": "Kreis Minden-Lübbecke",
    "h": "MInden",
    "s": "NW"
  },
  {
    "c": "MIL",
    "d": "Landkreis Miltenberg",
    "h": "MILtenberg",
    "s": "BY"
  },
  {
    "c": "MK",
    "d": "Märkischer Kreis",
    "h": "Märkischer Kreis",
    "s": "NW"
  },
  {
    "c": "MKK",
    "d": "Main-Kinzig-Kreis",
    "h": "Main-Kinzig-Kreis",
    "s": "HE"
  },
  {
    "c": "ML",
    "d": "Landkreis Mansfeld-Südharz",
    "h": "Mansfelder Land",
    "s": "ST"
  },
  {
    "c": "MM",
    "d": "Stadt Memmingen",
    "h": "MemMingen",
    "s": "BY"
  },
  {
    "c": "MN",
    "d": "Landkreis Unterallgäu",
    "h": "MiNdelheim",
    "s": "BY"
  },
  {
    "c": "MO",
    "d": "Kreis Wesel",
    "h": "MOers",
    "s": "NW"
  },
  {
    "c": "MOD",
    "d": "Landkreis Ostallgäu",
    "h": "MarktOberDorf",
    "s": "BY"
  },
  {
    "c": "MOL",
    "d": "Landkreis Märkisch-Oderland",
    "h": "Märkisch-OderLand",
    "s": "BB",
    "p": 1
  },
  {
    "c": "MON",
    "d": "Städteregion Aachen",
    "h": "MONschau",
    "s": "NW"
  },
  {
    "c": "MOS",
    "d": "Neckar-Odenwald-Kreis",
    "h": "MOSbach",
    "s": "BW"
  },
  {
    "c": "MQ",
    "d": "Saalekreis",
    "h": "Merseburg, Querfurt",
    "s": "ST"
  },
  {
    "c": "MR",
    "d": "Landkreis Marburg-Biedenkopf",
    "h": "MaRburg",
    "s": "HE"
  },
  {
    "c": "MS",
    "d": "Stadt Münster",
    "h": "MünSter",
    "s": "NW",
    "p": 1
  },
  {
    "c": "MSE",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "Mecklenburgische SEenplatte",
    "s": "MV",
    "p": 1
  },
  {
    "c": "MSH",
    "d": "Landkreis Mansfeld-Südharz",
    "h": "Mansfeld, SüdHarz",
    "s": "ST"
  },
  {
    "c": "MSP",
    "d": "Landkreis Main-Spessart",
    "h": "Main, SPessart",
    "s": "BY"
  },
  {
    "c": "MST",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "Mecklenburg-STrelitz",
    "s": "MV"
  },
  {
    "c": "MTK",
    "d": "Main-Taunus-Kreis",
    "h": "Main-Taunus-Kreis",
    "s": "HE"
  },
  {
    "c": "MTL",
    "d": "Landkreis Leipzig",
    "h": "MuldenTaL",
    "s": "SN"
  },
  {
    "c": "MU",
    "d": "Landkreis München",
    "h": "Münchner Umland",
    "s": "BY"
  },
  {
    "c": "MÜ",
    "d": "Landkreis Mühldorf am Inn",
    "h": "MÜhldorf",
    "s": "BY"
  },
  {
    "c": "MÜB",
    "d": "Landkreis Bayreuth",
    "h": "MÜnchBerg",
    "s": "BY"
  },
  {
    "c": "MUC",
    "d": "Stadt München",
    "h": "MUenChen",
    "s": "BY"
  },
  {
    "c": "MÜL",
    "d": "Landkreis Breisgau-Hochschwarzwald",
    "h": "MÜLlheim",
    "s": "BW"
  },
  {
    "c": "MÜR",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "MÜRitz",
    "s": "MV"
  },
  {
    "c": "MVL",
    "d": "Mecklenburg-Vorpommern, Landesregierung, Landtag und Polizei",
    "h": "Mecklenburg-Vorpommerscher Landtag",
    "s": "MV"
  },
  {
    "c": "MW",
    "d": "Landkreis Mittelsachsen",
    "h": "MittWeida",
    "s": "SN"
  },
  {
    "c": "MY",
    "d": "Landkreis Mayen-Koblenz",
    "h": "MaYen",
    "s": "RP"
  },
  {
    "c": "MYK",
    "d": "Landkreis Mayen-Koblenz",
    "h": "MaYen, Koblenz",
    "s": "RP"
  },
  {
    "c": "MZ",
    "d": "Stadt Mainz",
    "h": "MainZ",
    "s": "RP",
    "p": 1
  },
  {
    "c": "MZG",
    "d": "Landkreis Merzig-Wadern",
    "h": "MerZiG",
    "s": "SL"
  },
  {
    "c": "N",
    "d": "Stadt Nürnberg",
    "h": "Nürnberg",
    "s": "BY",
    "p": 1
  },
  {
    "c": "NAB",
    "d": "Landkreis Amberg-Sulzbach",
    "h": "NABburg",
    "s": "BY"
  },
  {
    "c": "NAI",
    "d": "Landkreis Hof",
    "h": "NAIla",
    "s": "BY"
  },
  {
    "c": "NAU",
    "d": "Landkreis Havelland",
    "h": "NAUen",
    "s": "BB"
  },
  {
    "c": "NB",
    "d": "Stadt Neubrandenburg (große kreisangehörige Stadt im Landkreis Mecklenburgische Seenplatte)",
    "h": "NeuBrandenburg",
    "s": "MV"
  },
  {
    "c": "ND",
    "d": "Landkreis Neuburg-Schrobenhausen",
    "h": "Neuburg an der Donau",
    "s": "BY"
  },
  {
    "c": "NDH",
    "d": "Landkreis Nordhausen",
    "h": "NorDHausen",
    "s": "TH"
  },
  {
    "c": "NE",
    "d": "Rhein-Kreis Neuss",
    "h": "NEuss",
    "s": "NW"
  },
  {
    "c": "NEA",
    "d": "Landkreis Neustadt an der Aisch-Bad Windsheim",
    "h": "NEustadt an der Aisch",
    "s": "BY"
  },
  {
    "c": "NEB",
    "d": "Burgenlandkreis",
    "h": "NEBra",
    "s": "ST"
  },
  {
    "c": "NEC",
    "d": "Stadt Coburg und Landkreis Coburg (→ Zweckverband Zulassungsstelle Coburg)",
    "h": "NEustadt bei Coburg",
    "s": "BY"
  },
  {
    "c": "NEN",
    "d": "Landkreis Schwandorf",
    "h": "NEuNburg",
    "s": "BY"
  },
  {
    "c": "NES",
    "d": "Landkreis Rhön-Grabfeld",
    "h": "NEustadt an der Saale",
    "s": "BY"
  },
  {
    "c": "NEU",
    "d": "Landkreis Breisgau-Hochschwarzwald",
    "h": "NEUstadt",
    "s": "BW"
  },
  {
    "c": "NEW",
    "d": "Landkreis Neustadt an der Waldnaab",
    "h": "NEustadt an der Waldnaab",
    "s": "BY"
  },
  {
    "c": "NF",
    "d": "Kreis Nordfriesland",
    "h": "NordFriesland",
    "s": "SH"
  },
  {
    "c": "NH",
    "d": "Landkreis Sonneberg",
    "h": "NeuHaus",
    "s": "TH"
  },
  {
    "c": "NI",
    "d": "Landkreis Nienburg/Weser",
    "h": "NIenburg",
    "s": "NI"
  },
  {
    "c": "NK",
    "d": "Landkreis Neunkirchen",
    "h": "NeunKirchen",
    "s": "SL"
  },
  {
    "c": "NL",
    "d": "Niedersachsen, Landesregierung und Landtag",
    "h": "Niedersächsischer Landtag",
    "s": "NI"
  },
  {
    "c": "NM",
    "d": "Landkreis Neumarkt in der Oberpfalz",
    "h": "NeuMarkt",
    "s": "BY"
  },
  {
    "c": "NMB",
    "d": "Burgenlandkreis",
    "h": "NauMBurg",
    "s": "ST"
  },
  {
    "c": "NMS",
    "d": "Stadt Neumünster",
    "h": "NeuMünSter",
    "s": "SH"
  },
  {
    "c": "NÖ",
    "d": "Landkreis Donau-Ries",
    "h": "NÖrdlingen",
    "s": "BY"
  },
  {
    "c": "NOH",
    "d": "Landkreis Grafschaft Bentheim",
    "h": "NOrdHorn",
    "s": "NI"
  },
  {
    "c": "NOL",
    "d": "Landkreis Görlitz",
    "h": "Niederschlesische OberLausitz",
    "s": "SN"
  },
  {
    "c": "NOM",
    "d": "Landkreis Northeim",
    "h": "NOrtheiM",
    "s": "NI"
  },
  {
    "c": "NOR",
    "d": "Landkreis Aurich",
    "h": "NORden",
    "s": "NI"
  },
  {
    "c": "NP",
    "d": "Landkreis Ostprignitz-Ruppin",
    "h": "NeurupPin",
    "s": "BB"
  },
  {
    "c": "NR",
    "d": "Landkreis Neuwied",
    "h": "Neuwied am Rhein",
    "s": "RP"
  },
  {
    "c": "NRW",
    "d": "Nordrhein-Westfalen, Landesregierung, Landtag und Polizei",
    "h": "NordRhein-Westfalen",
    "s": "NW"
  },
  {
    "c": "NT",
    "d": "Landkreis Esslingen",
    "h": "NürTingen",
    "s": "BW"
  },
  {
    "c": "NU",
    "d": "Landkreis Neu-Ulm",
    "h": "Neu-Ulm",
    "s": "BY"
  },
  {
    "c": "NVP",
    "d": "Landkreis Vorpommern-Rügen ohne die Stadt Stralsund",
    "h": "NordVorPommern",
    "s": "MV",
    "p": 1
  },
  {
    "c": "NW",
    "d": "Stadt Neustadt an der Weinstraße",
    "h": "Neustadt an der Weinstraße",
    "s": "RP"
  },
  {
    "c": "NWM",
    "d": "Landkreis Nordwestmecklenburg ohne die Stadt Wismar",
    "h": "NordWestMecklenburg",
    "s": "MV"
  },
  {
    "c": "NY",
    "d": "Landkreis Görlitz",
    "h": "NieskY",
    "s": "SN"
  },
  {
    "c": "NZ",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "NeustrelitZ",
    "s": "MV"
  },
  {
    "c": "OA",
    "d": "Landkreis Oberallgäu",
    "h": "OberAllgäu",
    "s": "BY"
  },
  {
    "c": "OAL",
    "d": "Landkreis Ostallgäu",
    "h": "OstALlgäu",
    "s": "BY"
  },
  {
    "c": "OB",
    "d": "Stadt Oberhausen",
    "h": "OBerhausen",
    "s": "NW",
    "p": 1
  },
  {
    "c": "OBB",
    "d": "Landkreis Miltenberg",
    "h": "OBernBurg",
    "s": "BY"
  },
  {
    "c": "OBG",
    "d": "Landkreis Stendal",
    "h": "OsterBurG",
    "s": "ST"
  },
  {
    "c": "OC",
    "d": "Landkreis Börde",
    "h": "OsChersleben",
    "s": "ST"
  },
  {
    "c": "OCH",
    "d": "Landkreis Würzburg",
    "h": "OCHsenfurt",
    "s": "BY"
  },
  {
    "c": "OD",
    "d": "Kreis Stormarn",
    "h": "OlDesloe",
    "s": "SH"
  },
  {
    "c": "OE",
    "d": "Kreis Olpe",
    "h": "OlpE",
    "s": "NW"
  },
  {
    "c": "OF",
    "d": "Landkreis Offenbach und Stadt Offenbach am Main",
    "h": "OFfenbach",
    "s": "HE"
  },
  {
    "c": "OG",
    "d": "Ortenaukreis",
    "h": "OffenburG",
    "s": "BW"
  },
  {
    "c": "OH",
    "d": "Kreis Ostholstein",
    "h": "OstHolstein",
    "s": "SH"
  },
  {
    "c": "OHA",
    "d": "Landkreis Göttingen ohne die Stadt Göttingen",
    "h": "Osterode am HArz",
    "s": "NI"
  },
  {
    "c": "ÖHR",
    "d": "Hohenlohekreis",
    "h": "ÖHRingen",
    "s": "BW"
  },
  {
    "c": "OHV",
    "d": "Landkreis Oberhavel",
    "h": "OberHaVel",
    "s": "BB",
    "p": 1
  },
  {
    "c": "OHZ",
    "d": "Landkreis Osterholz",
    "h": "OsterHolZ",
    "s": "NI"
  },
  {
    "c": "OK",
    "d": "Landkreis Börde",
    "h": "OhreKreis",
    "s": "ST"
  },
  {
    "c": "OL",
    "d": "Stadt Oldenburg",
    "h": "OLdenburg",
    "s": "NI"
  },
  {
    "c": "OP",
    "d": "Stadt Leverkusen",
    "h": "OPladen",
    "s": "NW"
  },
  {
    "c": "OPR",
    "d": "Landkreis Ostprignitz-Ruppin",
    "h": "OstPrignitz, Ruppin",
    "s": "BB"
  },
  {
    "c": "OS",
    "d": "Stadt Osnabrück",
    "h": "OSnabrück",
    "s": "NI"
  },
  {
    "c": "OSL",
    "d": "Landkreis Oberspreewald-Lausitz",
    "h": "OberSpreewald, Lausitz",
    "s": "BB"
  },
  {
    "c": "OTW",
    "d": "Landkreis Neunkirchen",
    "h": "OTtWeiler",
    "s": "SL"
  },
  {
    "c": "OVI",
    "d": "Landkreis Schwandorf",
    "h": "OberVIechtach",
    "s": "BY"
  },
  {
    "c": "OVL",
    "d": "Vogtlandkreis",
    "h": "OberVogtLand",
    "s": "SN"
  },
  {
    "c": "OZ",
    "d": "Landkreis Nordsachsen",
    "h": "OschatZ",
    "s": "SN"
  },
  {
    "c": "P",
    "d": "Stadt Potsdam",
    "h": "Potsdam",
    "s": "BB",
    "p": 1
  },
  {
    "c": "PA",
    "d": "Stadt Passau",
    "h": "PAssau",
    "s": "BY"
  },
  {
    "c": "PAF",
    "d": "Landkreis Pfaffenhofen an der Ilm",
    "h": "PfAFfenhofen",
    "s": "BY"
  },
  {
    "c": "PAN",
    "d": "Landkreis Rottal-Inn",
    "h": "PfArrkircheN",
    "s": "BY"
  },
  {
    "c": "PAR",
    "d": "Landkreis Kelheim",
    "h": "PARsberg",
    "s": "BY"
  },
  {
    "c": "PB",
    "d": "Kreis Paderborn",
    "h": "PaderBorn",
    "s": "NW"
  },
  {
    "c": "PCH",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "ParCHim",
    "s": "MV"
  },
  {
    "c": "PE",
    "d": "Landkreis Peine",
    "h": "PEine",
    "s": "NI"
  },
  {
    "c": "PEG",
    "d": "Landkreis Bayreuth",
    "h": "PEGnitz",
    "s": "BY"
  },
  {
    "c": "PF",
    "d": "Stadt Pforzheim",
    "h": "PForzheim",
    "s": "BW",
    "p": 1
  },
  {
    "c": "PI",
    "d": "Kreis Pinneberg",
    "h": "PInneberg",
    "s": "SH"
  },
  {
    "c": "PIR",
    "d": "Landkreis Sächsische Schweiz-Osterzgebirge",
    "h": "PIRna",
    "s": "SN",
    "p": 1
  },
  {
    "c": "PL",
    "d": "Vogtlandkreis",
    "h": "PLauen",
    "s": "SN"
  },
  {
    "c": "PLÖ",
    "d": "Kreis Plön",
    "h": "PLÖn",
    "s": "SH"
  },
  {
    "c": "PM",
    "d": "Landkreis Potsdam-Mittelmark",
    "h": "Potsdam, Mittelmark",
    "s": "BB",
    "p": 1
  },
  {
    "c": "PN",
    "d": "Saale-Orla-Kreis",
    "h": "PößNeck",
    "s": "TH"
  },
  {
    "c": "PR",
    "d": "Landkreis Prignitz",
    "h": "PRignitz",
    "s": "BB",
    "p": 1
  },
  {
    "c": "PRÜ",
    "d": "Eifelkreis Bitburg-Prüm",
    "h": "PRÜm",
    "s": "RP"
  },
  {
    "c": "PS",
    "d": "Stadt Pirmasens",
    "h": "PirmaSens",
    "s": "RP"
  },
  {
    "c": "PW",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "PaseWalk",
    "s": "MV"
  },
  {
    "c": "PZ",
    "d": "Landkreis Uckermark",
    "h": "PrenZlau",
    "s": "BB"
  },
  {
    "c": "QFT",
    "d": "Saalekreis",
    "h": "QuerFurT",
    "s": "ST"
  },
  {
    "c": "QLB",
    "d": "Landkreis Harz",
    "h": "QuedLinBurg",
    "s": "ST"
  },
  {
    "c": "R",
    "d": "Stadt Regensburg",
    "h": "Regensburg",
    "s": "BY",
    "p": 1
  },
  {
    "c": "RA",
    "d": "Landkreis Rastatt",
    "h": "RAstatt",
    "s": "BW"
  },
  {
    "c": "RC",
    "d": "Vogtlandkreis",
    "h": "ReiChenbach",
    "s": "SN"
  },
  {
    "c": "RD",
    "d": "Kreis Rendsburg-Eckernförde",
    "h": "RenDsburg",
    "s": "SH"
  },
  {
    "c": "RDG",
    "d": "Landkreis Vorpommern-Rügen ohne die Stadt Stralsund",
    "h": "Ribnitz-DamGarten",
    "s": "MV"
  },
  {
    "c": "RE",
    "d": "Kreis Recklinghausen",
    "h": "REcklinghausen",
    "s": "NW"
  },
  {
    "c": "REG",
    "d": "Landkreis Regen",
    "h": "REGen",
    "s": "BY"
  },
  {
    "c": "REH",
    "d": "Landkreis Hof",
    "h": "REHau",
    "s": "BY"
  },
  {
    "c": "REI",
    "d": "Landkreis Berchtesgadener Land",
    "h": "REIchenhall",
    "s": "BY"
  },
  {
    "c": "RG",
    "d": "Landkreis Meißen",
    "h": "Riesa, Großenhain",
    "s": "SN",
    "p": 1
  },
  {
    "c": "RH",
    "d": "Landkreis Roth",
    "h": "RotH",
    "s": "BY"
  },
  {
    "c": "RI",
    "d": "Landkreis Schaumburg",
    "h": "RInteln",
    "s": "NI"
  },
  {
    "c": "RID",
    "d": "Landkreis Kelheim",
    "h": "RIeDenburg",
    "s": "BY"
  },
  {
    "c": "RIE",
    "d": "Landkreis Meißen",
    "h": "RIEsa",
    "s": "SN"
  },
  {
    "c": "RL",
    "d": "Landkreis Mittelsachsen",
    "h": "RochLitz",
    "s": "SN"
  },
  {
    "c": "RM",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "Röbel/Müritz",
    "s": "MV"
  },
  {
    "c": "RN",
    "d": "Landkreis Havelland",
    "h": "RatheNow",
    "s": "BB"
  },
  {
    "c": "RO",
    "d": "Stadt Rosenheim",
    "h": "ROsenheim",
    "s": "BY",
    "p": 1
  },
  {
    "c": "ROD",
    "d": "Landkreis Cham",
    "h": "RODing",
    "s": "BY"
  },
  {
    "c": "ROF",
    "d": "Landkreis Hersfeld-Rotenburg",
    "h": "ROtenburg an der Fulda",
    "s": "HE"
  },
  {
    "c": "ROK",
    "d": "Donnersbergkreis",
    "h": "ROcKenhausen",
    "s": "RP"
  },
  {
    "c": "ROL",
    "d": "Landkreis Kelheim",
    "h": "ROttenburg an der Laaber",
    "s": "BY"
  },
  {
    "c": "ROS",
    "d": "Landkreis Rostock",
    "h": "ROStock",
    "s": "MV"
  },
  {
    "c": "ROT",
    "d": "Landkreis Ansbach",
    "h": "ROthenburg ob der Tauber",
    "s": "BY"
  },
  {
    "c": "ROW",
    "d": "Landkreis Rotenburg (Wümme)",
    "h": "ROtenburg (Wümme)",
    "s": "NI"
  },
  {
    "c": "RP",
    "d": "Rhein-Pfalz-Kreis",
    "h": "Rhein-Pfalz",
    "s": "RP"
  },
  {
    "c": "RPL",
    "d": "Rheinland-Pfalz, Landesregierung, Landtag und Polizei",
    "h": "Rheinland-Pfälzischer Landtag",
    "s": "RP"
  },
  {
    "c": "RS",
    "d": "Stadt Remscheid",
    "h": "RemScheid",
    "s": "NW"
  },
  {
    "c": "RSL",
    "d": "Stadt Dessau-Roßlau",
    "h": "RosSLau",
    "s": "ST"
  },
  {
    "c": "RT",
    "d": "Landkreis Reutlingen",
    "h": "ReuTlingen",
    "s": "BW"
  },
  {
    "c": "RU",
    "d": "Landkreis Saalfeld-Rudolstadt",
    "h": "RUdolstadt",
    "s": "TH"
  },
  {
    "c": "RÜD",
    "d": "Rheingau-Taunus-Kreis",
    "h": "RÜDesheim",
    "s": "HE"
  },
  {
    "c": "RÜG",
    "d": "Landkreis Vorpommern-Rügen ohne die Stadt Stralsund",
    "h": "RÜGen",
    "s": "MV"
  },
  {
    "c": "RV",
    "d": "Landkreis Ravensburg",
    "h": "RaVensburg",
    "s": "BW"
  },
  {
    "c": "RW",
    "d": "Landkreis Rottweil",
    "h": "RottWeil",
    "s": "BW"
  },
  {
    "c": "RZ",
    "d": "Kreis Herzogtum Lauenburg",
    "h": "RatZeburg",
    "s": "SH"
  },
  {
    "c": "S",
    "d": "Stadt Stuttgart",
    "h": "Stuttgart",
    "s": "BW",
    "p": 1
  },
  {
    "c": "SAB",
    "d": "Landkreis Trier-Saarburg",
    "h": "SAarBurg",
    "s": "RP"
  },
  {
    "c": "SAD",
    "d": "Landkreis Schwandorf",
    "h": "SchwAnDorf",
    "s": "BY"
  },
  {
    "c": "SÄK",
    "d": "Landkreis Waldshut",
    "h": "SÄcKingen",
    "s": "BW"
  },
  {
    "c": "SAL",
    "d": "Saarland, Landesregierung, Landtag und Polizei",
    "h": "SAarländischer Landtag",
    "s": "SL"
  },
  {
    "c": "SAN",
    "d": "Landkreis Hof",
    "h": "StAdtsteiNach",
    "s": "BY"
  },
  {
    "c": "SAW",
    "d": "Altmarkkreis Salzwedel",
    "h": "SAlzWedel",
    "s": "ST"
  },
  {
    "c": "SB",
    "d": "Regionalverband Saarbrücken ohne die Stadt Völklingen",
    "h": "SaarBrücken",
    "s": "SL",
    "p": 1
  },
  {
    "c": "SBG",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "StrasBurG",
    "s": "MV"
  },
  {
    "c": "SBK",
    "d": "Salzlandkreis",
    "h": "SchöneBecK",
    "s": "ST"
  },
  {
    "c": "SC",
    "d": "Stadt Schwabach",
    "h": "SChwabach",
    "s": "BY"
  },
  {
    "c": "SCZ",
    "d": "Saale-Orla-Kreis",
    "h": "SChleiZ",
    "s": "TH"
  },
  {
    "c": "SDH",
    "d": "Kyffhäuserkreis",
    "h": "SonDersHausen",
    "s": "TH"
  },
  {
    "c": "SDL",
    "d": "Landkreis Stendal",
    "h": "StenDaL",
    "s": "ST"
  },
  {
    "c": "SDT",
    "d": "Landkreis Uckermark",
    "h": "SchweDT",
    "s": "BB"
  },
  {
    "c": "SE",
    "d": "Kreis Segeberg",
    "h": "SEgeberg",
    "s": "SH"
  },
  {
    "c": "SEB",
    "d": "Landkreis Sächsische Schweiz-Osterzgebirge",
    "h": "SEBnitz",
    "s": "SN"
  },
  {
    "c": "SEE",
    "d": "Landkreis Märkisch-Oderland",
    "h": "SEElow",
    "s": "BB"
  },
  {
    "c": "SEF",
    "d": "Landkreis Neustadt an der Aisch-Bad Windsheim",
    "h": "SchEinFeld",
    "s": "BY"
  },
  {
    "c": "SEL",
    "d": "Landkreis Wunsiedel im Fichtelgebirge",
    "h": "SELb",
    "s": "BY"
  },
  {
    "c": "SFB",
    "d": "Landkreis Oberspreewald-Lausitz",
    "h": "SenFtenBerg",
    "s": "BB"
  },
  {
    "c": "SFT",
    "d": "Salzlandkreis",
    "h": "StaßFurT",
    "s": "ST"
  },
  {
    "c": "SG",
    "d": "Stadt Solingen",
    "h": "SolinGen",
    "s": "NW"
  },
  {
    "c": "SGH",
    "d": "Landkreis Mansfeld-Südharz",
    "h": "SanGerHausen",
    "s": "ST"
  },
  {
    "c": "SH",
    "d": "Schleswig-Holstein, Landesregierung, Landtag und Polizei",
    "h": "Schleswig-Holstein",
    "s": "SH"
  },
  {
    "c": "SHA",
    "d": "Landkreis Schwäbisch Hall",
    "h": "Schwäbisch HAll",
    "s": "BW"
  },
  {
    "c": "SHG",
    "d": "Landkreis Schaumburg",
    "h": "StadtHaGen",
    "s": "NI"
  },
  {
    "c": "SHK",
    "d": "Saale-Holzland-Kreis",
    "h": "Saale-Holzland-Kreis",
    "s": "TH"
  },
  {
    "c": "SHL",
    "d": "Stadt Suhl",
    "h": "SuHL",
    "s": "TH"
  },
  {
    "c": "SI",
    "d": "Kreis Siegen-Wittgenstein",
    "h": "SIegen",
    "s": "NW"
  },
  {
    "c": "SIG",
    "d": "Landkreis Sigmaringen",
    "h": "SIGmaringen",
    "s": "BW"
  },
  {
    "c": "SIM",
    "d": "Rhein-Hunsrück-Kreis",
    "h": "SIMmern",
    "s": "RP"
  },
  {
    "c": "SK",
    "d": "Saalekreis",
    "h": "SaaleKreis",
    "s": "ST"
  },
  {
    "c": "SL",
    "d": "Kreis Schleswig-Flensburg",
    "h": "SchLeswig",
    "s": "SH"
  },
  {
    "c": "SLE",
    "d": "Kreis Düren",
    "h": "SchLEiden",
    "s": "NW"
  },
  {
    "c": "SLF",
    "d": "Landkreis Saalfeld-Rudolstadt",
    "h": "SaaLFeld",
    "s": "TH"
  },
  {
    "c": "SLG",
    "d": "Landkreis Ravensburg",
    "h": "SauLGau",
    "s": "BW"
  },
  {
    "c": "SLK",
    "d": "Salzlandkreis",
    "h": "SalzLandKreis",
    "s": "ST"
  },
  {
    "c": "SLN",
    "d": "Landkreis Altenburger Land",
    "h": "SchmölLN",
    "s": "TH"
  },
  {
    "c": "SLS",
    "d": "Landkreis Saarlouis",
    "h": "SaarLouiS",
    "s": "SL"
  },
  {
    "c": "SLÜ",
    "d": "Main-Kinzig-Kreis",
    "h": "SchLÜchtern",
    "s": "HE"
  },
  {
    "c": "SLZ",
    "d": "Wartburgkreis",
    "h": "SaLZungen",
    "s": "TH"
  },
  {
    "c": "SM",
    "d": "Landkreis Schmalkalden-Meiningen",
    "h": "SchMalkalden",
    "s": "TH"
  },
  {
    "c": "SMÜ",
    "d": "Landkreis Augsburg",
    "h": "SchwabMÜnchen",
    "s": "BY"
  },
  {
    "c": "SN",
    "d": "Stadt Schwerin",
    "h": "SchweriN",
    "s": "MV",
    "p": 1
  },
  {
    "c": "SNH",
    "d": "Rhein-Neckar-Kreis",
    "h": "SiNsHeim",
    "s": "BW"
  },
  {
    "c": "SO",
    "d": "Kreis Soest",
    "h": "SOest",
    "s": "NW"
  },
  {
    "c": "SOB",
    "d": "Landkreis Neuburg-Schrobenhausen",
    "h": "SchrOBenhausen",
    "s": "BY"
  },
  {
    "c": "SOG",
    "d": "Landkreis Weilheim-Schongau",
    "h": "SchOnGau",
    "s": "BY"
  },
  {
    "c": "SOK",
    "d": "Saale-Orla-Kreis",
    "h": "Saale-Orla-Kreis",
    "s": "TH",
    "p": 1
  },
  {
    "c": "SÖM",
    "d": "Landkreis Sömmerda",
    "h": "SÖMmerda",
    "s": "TH"
  },
  {
    "c": "SON",
    "d": "Landkreis Sonneberg",
    "h": "SONneberg",
    "s": "TH"
  },
  {
    "c": "SP",
    "d": "Stadt Speyer",
    "h": "SPeyer",
    "s": "RP"
  },
  {
    "c": "SPB",
    "d": "Landkreis Spree-Neiße",
    "h": "SPremBerg",
    "s": "BB"
  },
  {
    "c": "SPN",
    "d": "Landkreis Spree-Neiße",
    "h": "SPree, Neiße",
    "s": "BB",
    "p": 1
  },
  {
    "c": "SR",
    "d": "Landkreis Straubing-Bogen",
    "h": "StRaubing",
    "s": "BY"
  },
  {
    "c": "SRB",
    "d": "Landkreis Märkisch-Oderland",
    "h": "StRausBerg",
    "s": "BB"
  },
  {
    "c": "SRO",
    "d": "Saale-Holzland-Kreis",
    "h": "StadtROda",
    "s": "TH"
  },
  {
    "c": "ST",
    "d": "Kreis Steinfurt",
    "h": "STeinfurt",
    "s": "NW"
  },
  {
    "c": "STA",
    "d": "Landkreis Starnberg",
    "h": "STArnberg",
    "s": "BY"
  },
  {
    "c": "STB",
    "d": "Landkreis Ludwigslust-Parchim",
    "h": "STernBerg",
    "s": "MV"
  },
  {
    "c": "STD",
    "d": "Landkreis Stade",
    "h": "STaDe",
    "s": "NI"
  },
  {
    "c": "STE",
    "d": "Landkreis Lichtenfels",
    "h": "STaffelstEin",
    "s": "BY"
  },
  {
    "c": "STL",
    "d": "Erzgebirgskreis",
    "h": "STolLberg",
    "s": "SN"
  },
  {
    "c": "STO",
    "d": "Landkreis Konstanz",
    "h": "STOckach",
    "s": "BW"
  },
  {
    "c": "SU",
    "d": "Rhein-Sieg-Kreis",
    "h": "SiegbUrg",
    "s": "NW"
  },
  {
    "c": "SUL",
    "d": "Landkreis Amberg-Sulzbach",
    "h": "SULzbach-Rosenberg",
    "s": "BY"
  },
  {
    "c": "SÜW",
    "d": "Landkreis Südliche Weinstraße",
    "h": "SÜdliche Weinstraße",
    "s": "RP"
  },
  {
    "c": "SW",
    "d": "Stadt Schweinfurt",
    "h": "SchWeinfurt",
    "s": "BY"
  },
  {
    "c": "SWA",
    "d": "Rheingau-Taunus-Kreis",
    "h": "SchWAlbach",
    "s": "HE"
  },
  {
    "c": "SY",
    "d": "Landkreis Diepholz",
    "h": "SYke",
    "s": "NI"
  },
  {
    "c": "SZ",
    "d": "Stadt Salzgitter",
    "h": "SalZgitter",
    "s": "NI"
  },
  {
    "c": "SZB",
    "d": "Erzgebirgskreis",
    "h": "SchwarZenBerg",
    "s": "SN"
  },
  {
    "c": "TBB",
    "d": "Main-Tauber-Kreis",
    "h": "TauBerBischofsheim",
    "s": "BW"
  },
  {
    "c": "TDO",
    "d": "Landkreis Nordsachsen",
    "h": "Torgau, Delitzsch, Oschatz",
    "s": "SN",
    "p": 1
  },
  {
    "c": "TE",
    "d": "Kreis Steinfurt",
    "h": "TEcklenburg",
    "s": "NW"
  },
  {
    "c": "TET",
    "d": "Landkreis Rostock",
    "h": "TETerow",
    "s": "MV"
  },
  {
    "c": "TF",
    "d": "Landkreis Teltow-Fläming",
    "h": "Teltow, Fläming",
    "s": "BB",
    "p": 1
  },
  {
    "c": "TG",
    "d": "Landkreis Nordsachsen",
    "h": "TorGau",
    "s": "SN"
  },
  {
    "c": "THL",
    "d": "Thüringen, Landesregierung und Landtag",
    "h": "THüringer Landtag",
    "s": "TH"
  },
  {
    "c": "THW",
    "d": "Bundesanstalt Technisches Hilfswerk",
    "h": "Technisches HilfsWerk",
    "s": "BUND"
  },
  {
    "c": "TIR",
    "d": "Landkreis Tirschenreuth",
    "h": "TIRschenreuth",
    "s": "BY"
  },
  {
    "c": "TO",
    "d": "Landkreis Nordsachsen",
    "h": "Torgau, Oschatz",
    "s": "SN"
  },
  {
    "c": "TÖL",
    "d": "Landkreis Bad Tölz-Wolfratshausen",
    "h": "TÖLz",
    "s": "BY"
  },
  {
    "c": "TP",
    "d": "Landkreis Uckermark",
    "h": "TemPlin",
    "s": "BB"
  },
  {
    "c": "TR",
    "d": "Landkreis Trier-Saarburg und Stadt Trier",
    "h": "TRier",
    "s": "RP",
    "p": 1
  },
  {
    "c": "TS",
    "d": "Landkreis Traunstein",
    "h": "TraunStein",
    "s": "BY"
  },
  {
    "c": "TT",
    "d": "Bodenseekreis",
    "h": "TeTtnang",
    "s": "BW"
  },
  {
    "c": "TÜ",
    "d": "Landkreis Tübingen",
    "h": "TÜbingen",
    "s": "BW"
  },
  {
    "c": "TUT",
    "d": "Landkreis Tuttlingen",
    "h": "TUTtlingen",
    "s": "BW"
  },
  {
    "c": "ÜB",
    "d": "Bodenseekreis",
    "h": "ÜBerlingen",
    "s": "BW"
  },
  {
    "c": "UE",
    "d": "Landkreis Uelzen",
    "h": "UElzen",
    "s": "NI"
  },
  {
    "c": "UEM",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "UEckerMünde",
    "s": "MV"
  },
  {
    "c": "UFF",
    "d": "Landkreis Neustadt an der Aisch-Bad Windsheim",
    "h": "UFFenheim",
    "s": "BY"
  },
  {
    "c": "UH",
    "d": "Unstrut-Hainich-Kreis",
    "h": "Unstrut, Hainich",
    "s": "TH"
  },
  {
    "c": "UL",
    "d": "Stadt Ulm",
    "h": "ULm",
    "s": "BW",
    "p": 1
  },
  {
    "c": "UM",
    "d": "Landkreis Uckermark",
    "h": "UckerMark",
    "s": "BB"
  },
  {
    "c": "UN",
    "d": "Kreis Unna",
    "h": "UNna",
    "s": "NW"
  },
  {
    "c": "USI",
    "d": "Hochtaunuskreis",
    "h": "USIngen",
    "s": "HE"
  },
  {
    "c": "V",
    "d": "Vogtlandkreis",
    "h": "Vogtland",
    "s": "SN",
    "p": 1
  },
  {
    "c": "VAI",
    "d": "Landkreis Ludwigsburg",
    "h": "VAIhingen",
    "s": "BW"
  },
  {
    "c": "VB",
    "d": "Vogelsbergkreis",
    "h": "VogelsBerg",
    "s": "HE"
  },
  {
    "c": "VEC",
    "d": "Landkreis Vechta",
    "h": "VEChta",
    "s": "NI"
  },
  {
    "c": "VER",
    "d": "Landkreis Verden",
    "h": "VERden",
    "s": "NI"
  },
  {
    "c": "VG",
    "d": "Landkreis Vorpommern-Greifswald",
    "h": "Vorpommern-Greifswald",
    "s": "MV",
    "p": 1
  },
  {
    "c": "VIB",
    "d": "Landkreis Landshut",
    "h": "VIlsBiburg",
    "s": "BY"
  },
  {
    "c": "VIE",
    "d": "Kreis Viersen",
    "h": "VIErsen",
    "s": "NW"
  },
  {
    "c": "VIT",
    "d": "Landkreis Regen",
    "h": "VIechTach",
    "s": "BY"
  },
  {
    "c": "VK",
    "d": "Stadt Völklingen (Mittelstadt im Regionalverband Saarbrücken)",
    "h": "VölKlingen",
    "s": "SL"
  },
  {
    "c": "VOH",
    "d": "Landkreis Neustadt an der Waldnaab",
    "h": "VOHenstrauß",
    "s": "BY"
  },
  {
    "c": "VR",
    "d": "Landkreis Vorpommern-Rügen ohne die Stadt Stralsund",
    "h": "Vorpommern-Rügen",
    "s": "MV",
    "p": 1
  },
  {
    "c": "VS",
    "d": "Schwarzwald-Baar-Kreis",
    "h": "Villingen-Schwenningen",
    "s": "BW"
  },
  {
    "c": "W",
    "d": "Stadt Wuppertal",
    "h": "Wuppertal",
    "s": "NW",
    "p": 1
  },
  {
    "c": "WA",
    "d": "Landkreis Waldeck-Frankenberg",
    "h": "WAldeck",
    "s": "HE"
  },
  {
    "c": "WAF",
    "d": "Kreis Warendorf",
    "h": "WArendorF",
    "s": "NW"
  },
  {
    "c": "WAK",
    "d": "Wartburgkreis",
    "h": "WArtburgKreis",
    "s": "TH"
  },
  {
    "c": "WAN",
    "d": "Stadt Herne",
    "h": "WANne",
    "s": "NW"
  },
  {
    "c": "WAR",
    "d": "Kreis Höxter",
    "h": "WARburg",
    "s": "NW"
  },
  {
    "c": "WAT",
    "d": "Stadt Bochum",
    "h": "WATtenscheid",
    "s": "NW"
  },
  {
    "c": "WB",
    "d": "Landkreis Wittenberg",
    "h": "WittenBerg",
    "s": "ST"
  },
  {
    "c": "WBS",
    "d": "Landkreis Eichsfeld",
    "h": "WorBiS",
    "s": "TH"
  },
  {
    "c": "WDA",
    "d": "Landkreis Zwickau",
    "h": "WerDAu",
    "s": "SN",
    "p": 1
  },
  {
    "c": "WE",
    "d": "Stadt Weimar",
    "h": "WEimar",
    "s": "TH"
  },
  {
    "c": "WEL",
    "d": "Landkreis Limburg-Weilburg",
    "h": "WEiLburg",
    "s": "HE"
  },
  {
    "c": "WEN",
    "d": "Stadt Weiden in der Oberpfalz",
    "h": "WEideN",
    "s": "BY"
  },
  {
    "c": "WER",
    "d": "Landkreis Augsburg",
    "h": "WERtingen",
    "s": "BY"
  },
  {
    "c": "WES",
    "d": "Kreis Wesel",
    "h": "WESel",
    "s": "NW"
  },
  {
    "c": "WF",
    "d": "Landkreis Wolfenbüttel",
    "h": "WolFenbüttel",
    "s": "NI"
  },
  {
    "c": "WG",
    "d": "Landkreis Ravensburg",
    "h": "WanGen",
    "s": "BW"
  },
  {
    "c": "WHV",
    "d": "Stadt Wilhelmshaven",
    "h": "WilhelmsHaVen",
    "s": "NI"
  },
  {
    "c": "WI",
    "d": "Stadt Wiesbaden",
    "h": "WIesbaden",
    "s": "HE",
    "p": 1
  },
  {
    "c": "WIL",
    "d": "Landkreis Bernkastel-Wittlich",
    "h": "WIttLich",
    "s": "RP"
  },
  {
    "c": "WIS",
    "d": "Landkreis Nordwestmecklenburg ohne die Stadt Wismar",
    "h": "WISmar",
    "s": "MV"
  },
  {
    "c": "WIT",
    "d": "Ennepe-Ruhr-Kreis",
    "h": "WITten",
    "s": "NW"
  },
  {
    "c": "WIZ",
    "d": "Werra-Meißner-Kreis",
    "h": "WItZenhausen",
    "s": "HE"
  },
  {
    "c": "WK",
    "d": "Landkreis Ostprignitz-Ruppin",
    "h": "WittstocK",
    "s": "BB"
  },
  {
    "c": "WL",
    "d": "Landkreis Harburg",
    "h": "Winsen (Luhe)",
    "s": "NI"
  },
  {
    "c": "WLG",
    "d": "Landkreis Vorpommern-Greifswald ohne die Stadt Greifswald",
    "h": "WoLGast",
    "s": "MV"
  },
  {
    "c": "WM",
    "d": "Landkreis Weilheim-Schongau",
    "h": "WeilheiM",
    "s": "BY"
  },
  {
    "c": "WMS",
    "d": "Landkreis Börde",
    "h": "WolMirStedt",
    "s": "ST"
  },
  {
    "c": "WN",
    "d": "Rems-Murr-Kreis",
    "h": "WaiblingeN",
    "s": "BW"
  },
  {
    "c": "WND",
    "d": "Landkreis St. Wendel",
    "h": "WeNDel",
    "s": "SL"
  },
  {
    "c": "WO",
    "d": "Stadt Worms",
    "h": "WOrms",
    "s": "RP"
  },
  {
    "c": "WOB",
    "d": "Stadt Wolfsburg",
    "h": "WOlfsBurg",
    "s": "NI"
  },
  {
    "c": "WOH",
    "d": "Landkreis Kassel",
    "h": "WOlfHagen",
    "s": "HE"
  },
  {
    "c": "WOL",
    "d": "Landkreis Freudenstadt",
    "h": "WOLfach",
    "s": "BW"
  },
  {
    "c": "WOR",
    "d": "Landkreis Bad Tölz-Wolfratshausen",
    "h": "WOlfRatshausen",
    "s": "BY"
  },
  {
    "c": "WOS",
    "d": "Landkreis Freyung-Grafenau",
    "h": "WOlfStein",
    "s": "BY"
  },
  {
    "c": "WR",
    "d": "Landkreis Harz",
    "h": "WernigeRode",
    "s": "ST"
  },
  {
    "c": "WRN",
    "d": "Landkreis Mecklenburgische Seenplatte ohne die Stadt Neubrandenburg",
    "h": "WaReN",
    "s": "MV"
  },
  {
    "c": "WS",
    "d": "Landkreis Mühldorf a.Inn",
    "h": "WasSerburg",
    "s": "BY"
  },
  {
    "c": "WSF",
    "d": "Burgenlandkreis",
    "h": "WeisSenFels",
    "s": "ST"
  },
  {
    "c": "WST",
    "d": "Landkreis Ammerland",
    "h": "WesterSTede",
    "s": "NI"
  },
  {
    "c": "WSW",
    "d": "Landkreis Görlitz",
    "h": "WeisSWasser",
    "s": "SN"
  },
  {
    "c": "WT",
    "d": "Landkreis Waldshut",
    "h": "WaldshuT",
    "s": "BW"
  },
  {
    "c": "WTL",
    "d": "Landkreis Osnabrück",
    "h": "WitTLage",
    "s": "NI"
  },
  {
    "c": "WTM",
    "d": "Landkreis Wittmund",
    "h": "WitTMund",
    "s": "NI"
  },
  {
    "c": "WÜ",
    "d": "Stadt Würzburg",
    "h": "WÜrzburg",
    "s": "BY",
    "p": 1
  },
  {
    "c": "WUG",
    "d": "Landkreis Weißenburg-Gunzenhausen",
    "h": "WeißenbUrG",
    "s": "BY"
  },
  {
    "c": "WÜM",
    "d": "Landkreis Cham",
    "h": "WaldMÜnchen (mit einem Buchstabendreher)",
    "s": "BY"
  },
  {
    "c": "WUN",
    "d": "Landkreis Wunsiedel im Fichtelgebirge",
    "h": "WUNsiedel",
    "s": "BY"
  },
  {
    "c": "WUR",
    "d": "Landkreis Leipzig",
    "h": "WURzen",
    "s": "SN"
  },
  {
    "c": "WW",
    "d": "Westerwaldkreis",
    "h": "WesterWald",
    "s": "RP"
  },
  {
    "c": "WZ",
    "d": "Lahn-Dill-Kreis",
    "h": "WetZlar",
    "s": "HE"
  },
  {
    "c": "WZL",
    "d": "Landkreis Börde",
    "h": "WanZLeben",
    "s": "ST"
  },
  {
    "c": "X",
    "d": "Internationale Hauptquartiere (NATO)",
    "h": "NATO-Streitkräfte in Deutschland",
    "s": "BUND",
    "p": 1
  },
  {
    "c": "Y",
    "d": "Bundeswehr",
    "h": "Bundeswehr (freies Kürzel bei Gründung)",
    "s": "BUND",
    "p": 1
  },
  {
    "c": "Z",
    "d": "Landkreis Zwickau",
    "h": "Zwickau",
    "s": "SN",
    "p": 1
  },
  {
    "c": "ZE",
    "d": "Landkreis Anhalt-Bitterfeld",
    "h": "ZErbst",
    "s": "ST"
  },
  {
    "c": "ZEL",
    "d": "Landkreis Cochem-Zell",
    "h": "ZELl",
    "s": "RP"
  },
  {
    "c": "ZI",
    "d": "Landkreis Görlitz",
    "h": "ZIttau",
    "s": "SN"
  },
  {
    "c": "ZIG",
    "d": "Schwalm-Eder-Kreis",
    "h": "ZIeGenhain",
    "s": "HE"
  },
  {
    "c": "ZP",
    "d": "Erzgebirgskreis",
    "h": "ZschoPau",
    "s": "SN"
  },
  {
    "c": "ZR",
    "d": "Landkreis Greiz",
    "h": "ZeulenRoda",
    "s": "TH"
  },
  {
    "c": "ZS",
    "d": "Landkreis Teltow-Fläming",
    "h": "ZoSsen",
    "s": "BB"
  },
  {
    "c": "ZW",
    "d": "Stadt Zweibrücken",
    "h": "ZWeibrücken",
    "s": "RP"
  },
  {
    "c": "ZZ",
    "d": "Burgenlandkreis",
    "h": "ZeitZ",
    "s": "ST"
  }
];

    // Haptisches Feedback
    function triggerVibration(pattern = [30]) {
        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            try { navigator.vibrate(pattern); } catch (_) {}
        }
    }

    // HTML Escaping
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Vorname des Nutzers ermitteln
    function getUserFirstName(fallback = 'Du') {
        const meta = document.querySelector('meta[name="user-first-name"]');
        const val = meta ? meta.getAttribute('content') : '';
        return (val && val.trim()) ? val.trim() : fallback;
    }

    // Zufallszahl
    function randInt(max) {
        return Math.floor(Math.random() * max);
    }

    // Array mischen (Fisher-Yates)
    function shuffle(arr) {
        const a = [...arr];
        for (let i = a.length - 1; i > 0; i--) {
            const j = randInt(i + 1);
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    // =========================================================================
    // HAUPTOBJEKT: PlatesApp
    // =========================================================================
    const PlatesApp = {
        STORAGE_KEY: 'kai_games_plates_stats',
        activeView: 'search', // 'search' | 'quiz'
        searchPopularOnly: false,
        selectedStateFilter: 'all',

        // Quiz-Zustand
        quizMode: 'plate_to_place', // 'plate_to_place' | 'place_to_plate' | 'reveal'
        quizPool: 'all', // 'all' | 'popular' | 'state'
        quizStateFilter: 'SN',
        score: 0,
        streak: 0,
        maxStreak: 0,
        currentPlate: null,
        currentOptions: [],
        answered: false,
        revealShown: false,

        init() {
            this.loadStats();
            this.cacheDom();
            this.initSearch();
            this.initQuiz();
            this.updatePlayerScoreUI();
        },

        cacheDom() {
            this.container = document.getElementById('tool-pane-plates');
            this.viewSearch = document.getElementById('plates-view-search');
            this.viewQuiz = document.getElementById('plates-view-quiz');
            this.searchInput = document.getElementById('plates-search-input');
            this.searchClearBtn = document.querySelector('.js-plates-search-clear');
            this.stateSelect = document.getElementById('plates-state-filter');
            this.popularFilterBtn = document.querySelector('.js-plates-filter-popular');
            this.resultsSummary = document.getElementById('plates-results-summary');
            this.resultsGrid = document.getElementById('plates-results-list');

            // Quiz Dom
            this.quizStage = document.getElementById('plates-quiz-stage');
            this.quizQuestionTitle = document.getElementById('plates-quiz-question-title');
            this.quizPlateDisplay = document.getElementById('plates-quiz-plate-display');
            this.quizOptionsGrid = document.getElementById('plates-quiz-options-grid');
            this.quizFeedback = document.getElementById('plates-quiz-feedback');
            this.quizNextBtn = document.getElementById('plates-quiz-next-btn');
            this.quizRevealBox = document.getElementById('plates-quiz-reveal-box');
            this.quizRevealDetails = document.getElementById('plates-quiz-reveal-details');
            this.quizRevealTriggerBtn = document.getElementById('plates-quiz-reveal-trigger');
            this.quizRevealActions = document.getElementById('plates-quiz-reveal-actions');
            this.scoreDisplay = document.getElementById('plates-stat-score');
            this.streakDisplay = document.getElementById('plates-stat-streak');
            this.playerNameDisplay = document.getElementById('plates-player-name');
        },

        loadStats() {
            try {
                const saved = sessionStorage.getItem(this.STORAGE_KEY);
                if (saved) {
                    const p = JSON.parse(saved);
                    this.score = Number(p.score) || 0;
                    this.streak = Number(p.streak) || 0;
                    this.maxStreak = Number(p.maxStreak) || 0;
                }
            } catch (_) {}
        },

        saveStats() {
            try {
                sessionStorage.setItem(this.STORAGE_KEY, JSON.stringify({
                    score: this.score,
                    streak: this.streak,
                    maxStreak: this.maxStreak
                }));
            } catch (_) {}
        },

        updatePlayerScoreUI() {
            const name = getUserFirstName('Du');
            if (this.playerNameDisplay) this.playerNameDisplay.textContent = name;
            if (this.scoreDisplay) this.scoreDisplay.textContent = String(this.score);
            if (this.streakDisplay) {
                this.streakDisplay.textContent = `${this.streak} (Rekord: ${this.maxStreak})`;
            }
        },

        switchView(view) {
            this.activeView = view;
            if (this.viewSearch) this.viewSearch.classList.toggle('hidden', view !== 'search');
            if (this.viewQuiz) this.viewQuiz.classList.toggle('hidden', view !== 'quiz');

            document.querySelectorAll('.js-plates-view-btn').forEach(btn => {
                if (btn.dataset.view === view) {
                    btn.classList.add('active');
                    btn.classList.remove('btn-outline');
                } else {
                    btn.classList.remove('active');
                    btn.classList.add('btn-outline');
                }
            });
            triggerVibration([15]);
        },

        // =====================================================================
        // SUCHE & LEXIKON
        // =====================================================================
        initSearch() {
            this.renderSearchResults();
        },

        renderPlateBadgeHtml(code, isLarge = false) {
            const cls = isLarge ? 'euro-plate euro-plate-lg' : 'euro-plate';
            return `<div class="${cls}" aria-label="Kfz-Kennzeichen ${escapeHtml(code)}">` +
                `<div class="euro-plate-blue"><span class="euro-plate-stars">🇪🇺</span><span class="euro-plate-d">D</span></div>` +
                `<div class="euro-plate-text">${escapeHtml(code)}</div>` +
                `</div>`;
        },

        renderSearchResults() {
            if (!this.resultsGrid) return;
            const q = this.searchInput ? this.searchInput.value.trim().toLowerCase() : '';
            const stateFilter = this.stateSelect ? this.stateSelect.value : 'all';
            const popularOnly = this.searchPopularOnly;

            let filtered = PLATES_DATA.filter(item => {
                if (popularOnly && !item.p) return false;
                if (stateFilter !== 'all' && item.s !== stateFilter) return false;
                if (!q) return true;
                const cLower = item.c.toLowerCase();
                const dLower = item.d.toLowerCase();
                const hLower = (item.h || '').toLowerCase();
                const sNameLower = (STATES[item.s] || '').toLowerCase();
                return cLower.includes(q) || dLower.includes(q) || hLower.includes(q) || sNameLower.includes(q);
            });

            // Sortierung bei Suchtext: Exakter Treffer zuerst, Kürzel-Beginn als Zweites
            if (q) {
                filtered.sort((a, b) => {
                    const aCode = a.c.toLowerCase();
                    const bCode = b.c.toLowerCase();
                    if (aCode === q && bCode !== q) return -1;
                    if (bCode === q && aCode !== q) return 1;
                    if (aCode.startsWith(q) && !bCode.startsWith(q)) return -1;
                    if (bCode.startsWith(q) && !aCode.startsWith(q)) return 1;
                    return a.c.localeCompare(b.c, 'de');
                });
            }

            if (this.resultsSummary) {
                const count = filtered.length;
                this.resultsSummary.textContent = `${count} Kennzeichen gefunden` + (q ? ` für „${this.searchInput.value.trim()}“` : '');
            }

            // Bis zu 120 anzeigen für flüssiges DOM-Rendering
            const displayItems = filtered.slice(0, 120);
            if (displayItems.length === 0) {
                this.resultsGrid.innerHTML = `<div class="plates-no-results text-muted">Keine Kennzeichen gefunden. Probiere einen anderen Suchbegriff oder setze den Bundesland-Filter zurück.</div>`;
                return;
            }

            let html = displayItems.map(item => {
                const stateName = STATES[item.s] || item.s;
                const herleitung = item.h ? `<span class="plates-item-derived">Herkunft: <strong>${escapeHtml(item.h)}</strong></span>` : '';
                return `<div class="plates-result-card" data-code="${escapeHtml(item.c)}">` +
                    `<div class="plates-card-badge-col">${this.renderPlateBadgeHtml(item.c)}</div>` +
                    `<div class="plates-card-info-col">` +
                    `<div class="plates-item-district">${escapeHtml(item.d)}</div>` +
                    `<div class="plates-item-meta">${herleitung}<span class="plates-item-state">📍 ${escapeHtml(stateName)}</span></div>` +
                    `</div>` +
                    `</div>`;
            }).join('');

            if (filtered.length > 120) {
                html += `<div class="plates-overflow-hint text-muted text-sm">+ ${filtered.length - 120} weitere Ergebnisse (Suchbegriff eingeben, um einzugrenzen)</div>`;
            }

            this.resultsGrid.innerHTML = html;
        },

        // =====================================================================
        // QUIZ ENGINE
        // =====================================================================
        initQuiz() {
            this.loadNextQuestion();
        },

        getQuizPool() {
            if (this.quizPool === 'popular') {
                return PLATES_DATA.filter(x => x.p);
            }
            if (this.quizPool === 'state') {
                const st = this.quizStateFilter || 'SN';
                const p = PLATES_DATA.filter(x => x.s === st);
                return p.length >= 4 ? p : PLATES_DATA;
            }
            return PLATES_DATA;
        },

        loadNextQuestion() {
            this.answered = false;
            this.revealShown = false;
            const pool = this.getQuizPool();
            this.currentPlate = pool[randInt(pool.length)];

            // 3 Distraktoren ziehen
            const distractors = [];
            const usedCodes = new Set([this.currentPlate.c]);
            let attempts = 0;
            while (distractors.length < 3 && attempts < 200) {
                attempts++;
                const candidate = pool[randInt(pool.length)];
                if (!usedCodes.has(candidate.c)) {
                    usedCodes.add(candidate.c);
                    distractors.push(candidate);
                }
            }
            // Falls Pool zu klein, aus Gesamt-Pool auffüllen
            while (distractors.length < 3) {
                const candidate = PLATES_DATA[randInt(PLATES_DATA.length)];
                if (!usedCodes.has(candidate.c)) {
                    usedCodes.add(candidate.c);
                    distractors.push(candidate);
                }
            }

            this.currentOptions = shuffle([this.currentPlate, ...distractors]);
            this.renderQuizUI();
        },

        renderQuizUI() {
            if (!this.quizStage) return;
            const plate = this.currentPlate;
            const stateName = STATES[plate.s] || plate.s;

            // Feedback verstecken
            if (this.quizFeedback) {
                this.quizFeedback.classList.add('hidden');
                this.quizFeedback.textContent = '';
            }
            if (this.quizNextBtn) {
                this.quizNextBtn.classList.add('hidden');
            }

            if (this.quizMode === 'plate_to_place') {
                if (this.quizQuestionTitle) this.quizQuestionTitle.textContent = 'Zu welcher Stadt oder welchem Landkreis gehört dieses Kennzeichen?';
                if (this.quizPlateDisplay) {
                    this.quizPlateDisplay.classList.remove('hidden');
                    this.quizPlateDisplay.innerHTML = this.renderPlateBadgeHtml(plate.c, true);
                }
                if (this.quizOptionsGrid) {
                    this.quizOptionsGrid.classList.remove('hidden');
                    this.quizOptionsGrid.innerHTML = this.currentOptions.map((opt, idx) => {
                        const optState = STATES[opt.s] || opt.s;
                        return `<button type="button" class="btn btn-outline plates-quiz-opt-btn js-quiz-choice-btn" data-index="${idx}">` +
                            `<strong>${escapeHtml(opt.d)}</strong>` +
                            `<span class="plates-opt-sub text-muted">${escapeHtml(optState)}</span>` +
                            `</button>`;
                    }).join('');
                }
                if (this.quizRevealBox) this.quizRevealBox.classList.add('hidden');
            } else if (this.quizMode === 'place_to_plate') {
                if (this.quizQuestionTitle) {
                    this.quizQuestionTitle.innerHTML = `Welches Kennzeichen gehört zu: <strong>${escapeHtml(plate.d)}</strong> (${escapeHtml(stateName)})?`;
                }
                if (this.quizPlateDisplay) this.quizPlateDisplay.classList.add('hidden');
                if (this.quizOptionsGrid) {
                    this.quizOptionsGrid.classList.remove('hidden');
                    this.quizOptionsGrid.innerHTML = this.currentOptions.map((opt, idx) => {
                        return `<button type="button" class="btn btn-outline plates-quiz-plate-btn js-quiz-choice-btn" data-index="${idx}">` +
                            this.renderPlateBadgeHtml(opt.c) +
                            `</button>`;
                    }).join('');
                }
                if (this.quizRevealBox) this.quizRevealBox.classList.add('hidden');
            } else if (this.quizMode === 'reveal') {
                if (this.quizQuestionTitle) this.quizQuestionTitle.textContent = 'Wohin gehört dieses Kennzeichen?';
                if (this.quizPlateDisplay) {
                    this.quizPlateDisplay.classList.remove('hidden');
                    this.quizPlateDisplay.innerHTML = this.renderPlateBadgeHtml(plate.c, true);
                }
                if (this.quizOptionsGrid) this.quizOptionsGrid.classList.add('hidden');
                if (this.quizRevealBox) {
                    this.quizRevealBox.classList.remove('hidden');
                    if (this.quizRevealDetails) this.quizRevealDetails.classList.add('hidden');
                    if (this.quizRevealTriggerBtn) this.quizRevealTriggerBtn.classList.remove('hidden');
                    if (this.quizRevealActions) this.quizRevealActions.classList.add('hidden');
                }
            }
        },

        checkChoice(choiceIndex) {
            if (this.answered) return;
            this.answered = true;

            const chosen = this.currentOptions[choiceIndex];
            const correct = (chosen && chosen.c === this.currentPlate.c);
            const plate = this.currentPlate;
            const stateName = STATES[plate.s] || plate.s;
            const herleitung = plate.h ? ` (Herkunft: „${plate.h}“)` : '';

            const buttons = this.quizOptionsGrid.querySelectorAll('.js-quiz-choice-btn');
            buttons.forEach((btn, idx) => {
                btn.disabled = true;
                const opt = this.currentOptions[idx];
                if (opt.c === plate.c) {
                    btn.classList.add('is-correct');
                } else if (idx === choiceIndex && !correct) {
                    btn.classList.add('is-wrong');
                }
            });

            if (correct) {
                const bonus = Math.min(this.streak * 2, 20);
                this.score += 10 + bonus;
                this.streak += 1;
                if (this.streak > this.maxStreak) this.maxStreak = this.streak;
                triggerVibration([40, 50, 60]);

                if (this.quizFeedback) {
                    this.quizFeedback.className = 'plates-feedback-banner feedback-won';
                    this.quizFeedback.innerHTML = `🎉 <strong>Richtig!</strong> ${escapeHtml(plate.c)} = ${escapeHtml(plate.d)}, ${escapeHtml(stateName)}${escapeHtml(herleitung)} (+${10 + bonus} Pkt.)`;
                    this.quizFeedback.classList.remove('hidden');
                }
            } else {
                this.streak = 0;
                triggerVibration([100, 60, 120]);

                if (this.quizFeedback) {
                    this.quizFeedback.className = 'plates-feedback-banner feedback-lost';
                    this.quizFeedback.innerHTML = `❌ <strong>Leider falsch!</strong> ${escapeHtml(plate.c)} gehört zu <strong>${escapeHtml(plate.d)}</strong> (${escapeHtml(stateName)})${escapeHtml(herleitung)}.`;
                    this.quizFeedback.classList.remove('hidden');
                }
            }

            this.saveStats();
            this.updatePlayerScoreUI();

            if (this.quizNextBtn) {
                this.quizNextBtn.classList.remove('hidden');
            }
        },

        revealAnswer() {
            if (!this.currentPlate || this.revealShown) return;
            this.revealShown = true;
            const plate = this.currentPlate;
            const stateName = STATES[plate.s] || plate.s;
            const herleitung = plate.h ? `<br><small class="text-muted">Herkunft: „${escapeHtml(plate.h)}“</small>` : '';

            if (this.quizRevealTriggerBtn) this.quizRevealTriggerBtn.classList.add('hidden');
            if (this.quizRevealDetails) {
                this.quizRevealDetails.innerHTML = `
                    <div class="plates-reveal-result">
                        <div class="plates-reveal-city">${escapeHtml(plate.d)}</div>
                        <div class="plates-reveal-state">📍 ${escapeHtml(stateName)}${herleitung}</div>
                    </div>
                `;
                this.quizRevealDetails.classList.remove('hidden');
            }
            if (this.quizRevealActions) {
                this.quizRevealActions.classList.remove('hidden');
            }
            triggerVibration([25]);
        },

        handleRevealResult(known) {
            if (known) {
                const bonus = Math.min(this.streak * 2, 20);
                this.score += 10 + bonus;
                this.streak += 1;
                if (this.streak > this.maxStreak) this.maxStreak = this.streak;
                triggerVibration([40, 50]);
            } else {
                this.streak = 0;
                triggerVibration([100]);
            }
            this.saveStats();
            this.updatePlayerScoreUI();
            this.loadNextQuestion();
        },

        resetStats() {
            if (confirm('Möchtest du deinen Punktestand und Rekord zurücksetzen?')) {
                this.score = 0;
                this.streak = 0;
                this.maxStreak = 0;
                this.saveStats();
                this.updatePlayerScoreUI();
                triggerVibration([20]);
            }
        }
    };

    // =========================================================================
    // EVENT-DELEGATION (CSP-KONFORM)
    // =========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        const pane = document.getElementById('tool-pane-plates');
        if (!pane) return;

        PlatesApp.init();

        // Klick-Delegation
        pane.addEventListener('click', (e) => {
            // 1. Ansichts-Wechsler (Suche vs Quiz)
            const viewBtn = e.target.closest('.js-plates-view-btn');
            if (viewBtn) {
                PlatesApp.switchView(viewBtn.dataset.view);
                return;
            }

            // 2. Suche leeren
            if (e.target.closest('.js-plates-search-clear')) {
                if (PlatesApp.searchInput) {
                    PlatesApp.searchInput.value = '';
                    PlatesApp.renderSearchResults();
                    PlatesApp.searchInput.focus();
                }
                return;
            }

            // 3. Filter Beliebt / Alle
            const popBtn = e.target.closest('.js-plates-filter-popular');
            if (popBtn) {
                PlatesApp.searchPopularOnly = !PlatesApp.searchPopularOnly;
                popBtn.classList.toggle('active', PlatesApp.searchPopularOnly);
                popBtn.classList.toggle('btn-outline', !PlatesApp.searchPopularOnly);
                PlatesApp.renderSearchResults();
                return;
            }

            // 4. Quiz-Modus Schalter
            const qModeBtn = e.target.closest('.js-quiz-mode-btn');
            if (qModeBtn) {
                PlatesApp.quizMode = qModeBtn.dataset.mode;
                pane.querySelectorAll('.js-quiz-mode-btn').forEach(b => {
                    if (b === qModeBtn) {
                        b.classList.add('active');
                        b.classList.remove('btn-outline');
                    } else {
                        b.classList.remove('active');
                        b.classList.add('btn-outline');
                    }
                });
                PlatesApp.loadNextQuestion();
                return;
            }

            // 5. Quiz-Pool Schalter
            const qPoolBtn = e.target.closest('.js-quiz-pool-btn');
            if (qPoolBtn) {
                PlatesApp.quizPool = qPoolBtn.dataset.pool;
                pane.querySelectorAll('.js-quiz-pool-btn').forEach(b => {
                    if (b === qPoolBtn) {
                        b.classList.add('active');
                        b.classList.remove('btn-outline');
                    } else {
                        b.classList.remove('active');
                        b.classList.add('btn-outline');
                    }
                });
                PlatesApp.loadNextQuestion();
                return;
            }

            // 6. Quiz Antwort-Button
            const choiceBtn = e.target.closest('.js-quiz-choice-btn');
            if (choiceBtn && !choiceBtn.disabled) {
                const idx = Number(choiceBtn.dataset.index);
                PlatesApp.checkChoice(idx);
                return;
            }

            // 7. Quiz Nächste Frage Button
            if (e.target.closest('#plates-quiz-next-btn')) {
                PlatesApp.loadNextQuestion();
                return;
            }

            // 8. Aufdeck-Modus: Auflösen
            if (e.target.closest('#plates-quiz-reveal-trigger')) {
                PlatesApp.revealAnswer();
                return;
            }

            // 9. Aufdeck-Modus: Gewusst / Nicht gewusst
            if (e.target.closest('.js-reveal-known-btn')) {
                PlatesApp.handleRevealResult(true);
                return;
            }
            if (e.target.closest('.js-reveal-unknown-btn')) {
                PlatesApp.handleRevealResult(false);
                return;
            }

            // 10. Punktestand zurücksetzen
            if (e.target.closest('.js-plates-reset-score-btn')) {
                PlatesApp.resetStats();
                return;
            }
        });

        // Eingabe in Suchfeld (Live-Filter)
        if (PlatesApp.searchInput) {
            PlatesApp.searchInput.addEventListener('input', () => {
                const hasVal = PlatesApp.searchInput.value.trim().length > 0;
                if (PlatesApp.searchClearBtn) {
                    PlatesApp.searchClearBtn.classList.toggle('hidden', !hasVal);
                }
                PlatesApp.renderSearchResults();
            });
        }

        // Änderung Bundesland-Filter Suche
        if (PlatesApp.stateSelect) {
            PlatesApp.stateSelect.addEventListener('change', () => {
                PlatesApp.renderSearchResults();
            });
        }
    });
})();
