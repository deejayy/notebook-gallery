#!/usr/bin/php
<?php

	require('phpQuery.php');

	date_default_timezone_set('Europe/Budapest');
	define('TS', microtime(true));

	function alog($str) {
		$ret = "";

		printf("%s.%.4f: %s\n", date('Y-m-d H:i:s'), (microtime(true)-TS)*1000, $str);

		return $ret;
	}

	function clean($str) {
		$ret = "";

		if (is_array($str)) {
			foreach ($str as &$s) {
				$s = clean($s);
			}
		} else {
			$ret = trim(preg_replace(array('/[\r\n]|:$| $/', '/[\t ]+/'), array('', ' '), $str));
		}

		return $ret;
	}

	function basename_c($str) {
		$ret = "";

		$ret = basename($str);
		$ret = preg_replace('/[\?\&]/', '_', $ret);

		return $ret;
	}

	function curl_get_cache($url, $fn, $post = array()) {
		$fn = 'cache/' . $fn;
		if (file_exists($fn)) {
			alog('Curl cache: ' . $url . ' -> ' . $fn);
			$ret = file_get_contents($fn);
		} else {
			alog('Curl get: ' . $url . ' -> ' . $fn);
			$c = curl_init();
			curl_setopt($c, CURLOPT_URL, $url);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.4 (KHTML, like Gecko) Chrome/22.0.1229.94 Safari/537.4');
			curl_setopt($c, CURLOPT_REFERER, preg_replace('/(http:\/\/.*?\/).*/', '$1', $url));
			if (count($post)) {
				curl_setopt($c, CURLOPT_POSTFIELDS, $post);
			}
			$ret = curl_exec($c);
			curl_close($c);

			file_put_contents($fn, $ret);
		}

		return preg_replace('/<\?xml version="1.0" encoding="UTF-8"\?>/im', '', $ret);
	}

	$shops = array(
/*		'asusnotebook' => array(
			'domain' => 'http://asusnotebook.hu',
			'url' => 'http://asusnotebook.hu/termekek/search_q=%%25;lista=1/name=ASC?egylapon=600',
			'url_count' => 1,
			'list_post' => array(),
			'list_item' => '.listazas_content h1 a[href!="#"]',
			'nb_name' => '.content_title',
			'opt_names' => '.tulajd_name, .kibont_name',
			'opt_values' => '.tulajd_ertek, .kibont_ertek',
			'price_field' => '.eredeti_ar_brutto2:first span',
			'price_mul' => 1,
			'translit' => array(
				'Operációs rendszer' => 'OS',
				'Processzor' => 'CPU',
				'Memória' => 'RAM',
				'Merevlemez' => 'HDD',
				'Optikai meghajtók' => 'DVD',
				'Kijelző' => 'TFT',
				'Grafikus kártya' => 'GPU',
				'Mikrofon' => '',
				'Hangszoró' => 'Audio',
				'Webkamera' => 'Kam',
				'W-lan / Wifi' => 'WLAN',
				'VGA csatlakozó' => 'VGA ki',
				'USB csatlakozók' => 'USB',
				'HDMI csatlakozó' => 'HDMI',
				'Kártyaolvasó' => 'CR',
				'Nyelvezet' => '',
				'Méret' => '',
				'Súly' => '',
				'Akkumulátor' => 'Akku',
				'Csomag tartalma' => 'Tartozék',
				'Garancia' => 'Gar.',
				'Processzor gyártó' => 'CPU Gyártó',
				'Processzor tipus' => 'CPU Típus',
				'Processzor sebesség' => 'CPU Seb.',
				'Chipset' => '',
				'Memória tipus' => 'RAM Típus',
				'Megjegyzés' => 'Egyéb',
				'Merevlemez tipus' => 'HDD Típus',
				'Grafikus kártya tipusa' => 'GPU Típus',
				'Ár' => '',
				'Bluetooth' => 'BT',
				'Grafikus memória mérete' => 'GPU Mem',
				'Képernyő méret' => 'TFT Méret',
				'Felbontás' => 'TFT Felb.',
				'CPU' => '',
				'HDD' => '',
				'RAM' => '',
				'VGA' => '',
				'WLAN' => '',
				'USB' => '',
				'HDMI' => '',
				'LAN (RJ45)' => 'LAN',
				'Adapter' => '',
				'Nettó súly' => 'Súly',
				'Megnevezés' => 'Név',
				'Cellák száma' => 'Akku cella',
				'Tárolt energia' => 'Akku Wh',
				'Alap szín' => 'Szín',
				'Anyag' => '',
				'Chipset gyártó' => 'Chip Gyártó',
				'CPU gyártó' => 'CPU Gyártó',
				'CPU típus' => 'CPU Típus',
				'D-Sub ki' => 'D-SUB',
				'Hálózati csatlakozás' => 'LAN',
				'HDMI ki' => 'HDMI',
				'Jack 3,5mm' => 'Audio',
				'USB 2.0 ki' => 'USB 2',
				'Kijelző felbontás' => 'TFT Felb',
				'Kijelző felület' => 'TFT Méret',
				'Kijelző méret' => 'TFT Méret',
				'Kijelző típus' => 'TFT Típus',
				'Memória kártyaolvasó' => 'CR',
				'Kensington zár foglalat' => 'Kens',
				'Magasság (max.)' => 'Magas',
				'Mélység (max.)' => 'Mély',
				'Család' => 'Típus',
				'CPU család' => 'CPU Típus',
				'Operációs rendszer kompatibilitás' => 'OS +',
				'Memória modulok' => 'RAM +',
				'Memória mennyiség' => 'RAM',
				'Szélesség (max.)' => 'Széles',
				'Háttértár fordulatszám' => 'HDD Seb.',
				'Háttértár méret' => 'HDD Méret',
				'Háttértár típus' => 'HDD Típus',
				'Töltő teljesítmény' => 'AC Ah',
				'VGA típus' => 'GPU Típus',
				'WiFi' => 'WLAN',
				'ODD' => 'DVD',
				'Display port' => 'DP',
				'Egyéb' => '',
				'Mini D-SUB csatlakozó' => 'Mini D-SUB',
				'Képernyő méret: 17,3"-os' => '-',
				'Felbontás: WUXGA Full HD LED (1920x1080)' => '-',
				'CPU: Intel Core i7-3610QM' => '-',
				'HDD: 750 GB, 5400 RPM, SATA + 160 GB SSD' => '-',
				'USB 3.0 ki' => 'USB',
				'Memória foglalatok száma' => 'RAM +',
				'Optikai meghajtó' => 'DVD',
				'SSD' => '',
				'TPM' => '',
			),
			'opt_list' => array(
				'Ár' => 1,
				'CPU' => 1,
				'CPU Gyártó' => 1,
				'CPU Típus' => 1,
				'CPU Seb.' => 1,
				'Chip' => 1,
				'Chip Gyártó' => 1,
				'RAM' => 1,
				'RAM Típus' => 1,
				'HDD' => 1,
				'HDD Méret' => 1,
				'HDD Típus' => 1,
				'HDD Seb.' => 1,
				'DVD' => 1,
				'TFT' => 1,
				'TFT Méret' => 1,
				'TFT Felb' => 1,
				'GPU' => 1,
				'GPU Típus' => 1,
				'GPU Mem' => 1,
				'WLAN' => 1,
				'VGA ki' => 1,
				'USB' => 1,
				'HDMI' => 1,
				'Súly' => 1,
				'Akku' => 1,
				'Akku cella' => 1,
				'Akku Wh' => 1,
				'Gar.' => 1,
				'Mikrofon' => 1,
				'Audio' => 1,
				'Kam' => 1,
				'CR' => 1,
				'BT' => 1,
				'OS' => 1,
			),
		),
		'usanotebook' => array(
			'domain' => 'http://www.usanotebook.hu/',
			'url' => 'http://www.usanotebook.hu/laptop/index.php?mode=useprm&tplDt=subcat|%d||&prmID=3;;;;;',
			'url_count' => 1,
			'list_post' => array(),
			'list_item' => '.tboxPRtit a',
			'nb_name' => 'h1 a',
			'opt_names' => '.tjName',
			'opt_values' => '.tjValue',
			'price_field' => '.prcBr, .prcNtAkc.BD span',
			'price_mul' => 1/1.27,
			'translit' => array(
				'Általános jellemzők Felhasználás:' => 'Felh.',
				'Általános jellemzők Típus:' => 'Típus',
				'Általános jellemzők Állapot:' => 'Állapot',
				'Általános jellemzők Szín:' => 'Szín',
				'Általános jellemzők Akciós termék:' => 'Akciós',
				'Processzor Gyártó:' => 'CPU Gyártó',
				'Processzor Család:' => 'CPU Család',
				'Processzor Típus:' => 'CPU Típus',
				'Processzor Paraméterek:' => 'CPU +',
				'Memória Méret:' => 'RAM',
				'Memória Frekvencia:' => 'RAM Frek',
				'Memória Jellemzők:' => 'RAM Jell',
				'Merevlemez Méret:' => 'HDD Méret',
				'Merevlemez Tipus:' => 'HDD Típus',
				'Merevlemez Jellemzők:' => 'HDD Jell',
				'Optikai meghajtó Típus:' => 'DVD',
				'Kijelző Méret:' => 'TFT Méret',
				'Kijelző Felbontás:' => 'TFT Felb',
				'Kijelző Típus:' => 'TFT Típus',
				'Kijelző Jellemzők:' => 'TFT Jell',
				'Videókártya Gyártó:' => 'GPU Gyártó',
				'Videókártya Típus:' => 'GPU Típus',
				'Videókártya Jelleg:' => 'GPU Jelleg',
				'Akkumulátor Jellemzők:' => 'GPU Jell',
				'Egyéb technikai paraméretek Chipkészlet:' => 'Chip',
				'Egyéb technikai paraméretek Billentyűzet nyelve:' => 'Nyelvezet',
				'Egyéb technikai paraméretek Audio:' => 'Audio',
				'Egyéb technikai paraméretek Portok/egyebek:' => 'Portok',
				'Egyéb technikai paraméretek Port jellemzők:' => 'Port Jell',
				'Egyéb technikai paraméretek Bővítőhelyek:' => 'Slotok',
				'Egyéb technikai paraméretek Extrák:' => 'Egyéb',
				'Egyéb technikai paraméretek Hálózat:' => 'LAN',
				'Egyéb technikai paraméretek Hálózati jellemzők:' => 'LAN Jell',
				'Operációs rendszer Típus:' => 'OS',
				'Fizikai paraméterek Méret:' => 'Méret',
				'Fizikai paraméterek Súly:' => 'Súly',
				'Garancia Időtartam:' => 'Gar.',
				'Garancia Típus:' => 'Gar. Típus',
				'Egyéb technikai paraméretek Mutató eszköz:' => 'Touch',
				'Egyéb szoftverek Gyártói szoftverek:' => 'Tartozék',
				'Egyéb technikai paraméretek Billentyűzet jellemzői:' => 'Bill',
				'Fizikai paraméterek Szín:' => 'Szín',
				'Operációs rendszer Jellemzők:' => 'OS +',
			),
			'opt_list' => array(
				'Ár' => 1,
				'CPU Gyártó' => 1,
				'CPU Család' => 1,
				'CPU Típus' => 1,
				'CPU +' => 1,
				'Chip' => 1,
				'RAM' => 1,
				'RAM Frek' => 1,
				'RAM Jell' => 1,
				'HDD Méret' => 1,
				'HDD Típus' => 1,
				'HDD Jell' => 1,
				'DVD' => 1,
				'TFT Méret' => 1,
				'TFT Felb' => 1,
				'TFT Típus' => 1,
				'TFT Jell' => 1,
				'GPU Gyártó' => 1,
				'GPU Típus' => 1,
				'GPU Jelleg' => 1,
				'GPU Jell' => 1,
				'LAN' => 1,
				'LAN Jell' => 1,
				'Audio' => 1,
				'Portok' => 1,
				'Port Jell' => 1,
				'Slotok' => 1,
				'Súly' => 1,
				'Gar.' => 1,
				'Gar. Típus' => 1,
			),
		),
		'laptopszalon' => array(
			'domain' => '',
			'url' => 'http://www.laptopszalon.hu/webshop/search',
			'url_count' => 1, //37,
			'list_post' => array(
				'ajax' => 'true',
				'modul' => 'webshop',
				'mode' => 'search',
				'cat' => 'laptop_notebook',
				'by' => 'asc',
				'order' => 'action',
			),
			'list_post_counter' => 'pg',
			'list_item' => '.name > a',
			'nb_name' => '.prodtitle:first',
			'opt_names' => '.PARAMETER .name',
			'opt_values' => '.PARAMETER tr td > div',
			'price_field' => '.product_price',
			'price_mul' => 1/1.27,
			'translit' => array(
				'Gyártó: Gyártó' => 'Gyártó',
				'Gyártó: Család' => 'Család',
				'Gyártó: Modell' => 'Modell',
				'Processzor típus: Processzor típus' => 'CPU Típus',
				'Processzor típus: Számozás' => 'CPU +',
				'Processzor típus: Mag neve' => 'CPU Mag',
				'Memória méret: Memória méret' => 'RAM',
				'Memória méret: Frekvencia' => 'RAM Frek',
				'Merevlemez méret: Merevlemez méret' => 'HDD Méret',
				'Merevlemez méret: Tipus' => 'HDD Típus',
				'Kijelző méret: Kijelző méret' => 'TFT Méret',
				'Kijelző méret: Felbontás' => 'TFT Felb',
				'Kijelző méret: tipus' => 'TFT Típus',
				'Videókártya gyártó: Videókártya gyártó' => 'GPU Gyártó',
				'Videókártya gyártó: jellege' => 'GPU Jell',
				'Videókártya gyártó: Tipus' => 'GPU Típus',
				'Operációs rendszer: Operációs rendszer' => 'OS',
				'Operációs rendszer: Nyelv' => 'Nyelvezet',
				'Operációs rendszer: Bit' => 'OS +',
				'Operációs rendszer: Család' => 'OS Család',
				'Garancia időtartam: Garancia időtartam' => 'Gar.',
				'Garancia időtartam: Tipus' => 'Gar. Típus',
				'Biztosítás: Biztosítás' => 'Biztosítás',
				'<br> Csatlakozók (adatátviteli eszközök): <br> Csatlakozók (adatátviteli eszközök)' => 'Slotok',
				'<br> Csatlakozók (adatátviteli eszközök): USB port' => 'USB',
				'<br> Csatlakozók (adatátviteli eszközök): HDMI' => 'HDMI',
				'<br> Csatlakozók (adatátviteli eszközök): eSata csatlakozó' => 'eSata',
				'<br> Csatlakozók (adatátviteli eszközök): VGA kimenet' => 'VGA ki',
				'<br> Csatlakozók (adatátviteli eszközök): Kártyaolvasó' => 'CR',
				'<br> Csatlakozók (adatátviteli eszközök): LAN' => 'LAN',
				'<br> Csatlakozók (adatátviteli eszközök): Firewire' => 'FW',
				'<br> Csatlakozók (adatátviteli eszközök): WLAN' => 'WLAN',
				'<br> Csatlakozók (adatátviteli eszközök): Bluetooth' => 'BT',
				'<br> Csatlakozók (adatátviteli eszközök): Express kártya foglalat' => 'ExpressCard',
				'<br> Csatlakozók (adatátviteli eszközök): Webkamera' => 'Kam',
				'<br> Csatlakozók (adatátviteli eszközök): HSDPA' => 'HSPDA',
				'<br> Egyéb jellemzők: <br> Egyéb jellemzők' => 'Egyéb',
				'<br> Egyéb jellemzők: Optikai meghajtó típus' => 'DVD',
				'<br> Egyéb jellemzők: Akkumulátor cellaszám' => 'Akku cella',
				'<br> Egyéb jellemzők: Billentyűzet' => 'Bill',
				'<br> Egyéb jellemzők: Szin' => 'Szín',
				'<br> Egyéb jellemzők: Súly' => 'Súly',
				'Kijelző méret: Képarány' => 'TFT Arány',
				'<br> Egyéb jellemzők: jellemző' => 'Egyéb',
				'<br> Egyéb jellemzők: Audio' => 'Audio',
				'<br> Egyéb jellemzők: Chipkészlet' => 'Chip',
				'<br> Egyéb jellemzők: Ujjlenyomat olvasó' => 'FPR',
				'<br> Egyéb jellemzők: Jellemző' => 'Egyéb +',
				'<br> Egyéb jellemzők: Egyéb információk' => 'Egyéb ++',
				'<br> Egyéb jellemzők: Kensington zár' => 'Kens',
				'Videókártya gyártó: RAM mérete' => 'GPU Mem',
			),
			'opt_list' => array('Ár' => 1),
		),
		'notebookstore' => array(
			'domain' => 'http://notebookstore.hu/Notebook/',
			'url' => 'http://notebookstore.hu/kereses/pag_cikkcsoport.aspx?ccsop=COMNOT&ps=90&p=%d',
			'url_count' => 1, //6,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.nsCikkNev a',
			'nb_name' => '#myPrint h1',
			'opt_names' => '.nsCrMatmod',
			'opt_values' => '.nsCrMatmodErtek',
			'price_field' => '#ctl00_CON_default_UCO_CikkReszletek1_RPT_reszletek_ctl00_PAN_reszl div:contains("Nett")',
			'price_mul' => 1/100,
			'translit' => array(
				'Termék osztály' => 'Kategória',
				'Gyártói ajánlás' => 'Típus',
				'Processzor' => 'CPU',
				'Processzor Gyártó' => 'CPU Gyártó',
				'Processzor Család' => 'CPU Család',
				'Processzor Magok száma' => 'CPU Magok',
				'Processzor Órajel' => 'CPU Seb.',
				'Processzor Turbóórajel' => 'CPU Turbo',
				'Processzor Speed step' => 'CPU SS',
				'Processzor Cache' => 'CPU Cache',
				'Processzor Max memória' => 'CPU Max RAM',
				'Processzor 64 bit támogatás' => 'CPU 64',
				'Processzor Technológia' => 'CPU Mag',
				'Processzor Max fogyasztás' => 'CPU Fogy.',
				'Processzor Virtualizáció' => 'CPU Virt',
				'Processzor Hyperthreading' => 'CPU HT',
				'Kijelző' => 'TFT',
				'Kijelző Szabvány' => 'TFT Szabv.',
				'Kijelző Képátló' => 'TFT Méret',
				'Kijelző Felület' => 'TFT Felület',
				'Kijelző Felbontás' => 'TFT Felb.',
				'Kijelző Vizszintes felbontás' => 'TFT X',
				'Kijelző Függőleges felbontás' => 'TFT Y',
				'Kijelző Képarány' => 'TFT Arány',
				'Kijelző Technológia' => 'TFT Tech',
				'Kijelző Érintő' => 'TFT Touch',
				'Kijelző Beláthatóság' => 'TFT Szög',
				'Kijelző Színhű' => 'TFT Szín',
				'Videó vezérlő' => 'GPU',
				'Videó vezérlő Gyártó' => 'GPU Gyártó',
				'Videó vezérlő Család' => 'GPU Család',
				'Videó vezérlő Tipusa' => 'GPU Típus',
				'Videó vezérlő Saját memória' => 'GPU MEM',
				'Videó vezérlő Memória tipus' => 'GPU MEM Típus',
				'Videó vezérlő Technológia' => 'GPU Tech',
				'Videó vezérlő Direct x' => 'GPU DX',
				'Videó vezérlő Shader model' => 'GPU Shader',
				'Videó vezérlő Open gl' => 'GPU OGL',
				'Memória' => 'RAM',
				'Memória Memória mérete' => 'RAM Méret',
				'Memória Memória tipusa' => 'RAM Típus',
				'Háttértár' => 'HDD',
				'Első tipusa' => 'HDD Típus',
				'Optikai meghajtó' => 'ODD',
				'Optikai meghajtó Elhelyezkedés' => 'ODD Hely',
				'Optikai meghajtó Cd képesség' => 'ODD CD',
				'Optikai meghajtó Dvd képesség' => 'ODD DVD',
				'Optikai meghajtó Blueray képesség' => 'ODD BR',
				'Hálózat' => 'LAN',
				'Vezeték nélküli hálózat' => 'WLAN',
				'Bluetooth' => 'BT',
				'Kártyaolvasó' => 'CR',
				'Össz USB' => 'USB',
				'VGA' => 'VGA ki',
				'HDMI' => 'HDMI',
				'Hangkimenet' => 'Audio',
				'Beépített mikrofon' => 'Mikrofon',
				'Beépített webkamera' => 'Kamera',
				'Billentyűzet nyelv' => 'Nyelv',
				'Billentyűzet szine' => 'Bill. szín',
				'Gombok száma' => 'Bill. Gombok',
				'Fingerprint' => 'FP',
				'Kensington' => 'Kensington',
				'Akkumulátor' => 'Akku',
				'Akkumulátor Cellák száma' => 'Akku Cella',
				'Akkumulátor Teljesítmény' => 'Akku Wh',
				'Akkumulátor Tipusa' => 'Akku Típus',
				'Akkumulátor Max üzemidő' => 'Akku Idő',
				'Operációs rendszer' => 'OS',
				'Operációs rendszer Család' => 'OS Család',
				'Operációs rendszer Tipus' => 'OS Típus',
				'Operációs rendszer Nyelv' => 'OS Nyelv',
				'Operációs rendszer Bitszám' => 'OS 32/64',
				'Tömeg' => 'Súly',
				'X méret' => 'Széles',
				'Y méret' => 'Mély',
				'Z méret' => 'Magas',
				'Modell' => 'Modell',
				'Modem' => 'Modem',
				'USB 3-k száma' => 'USB 3',
				'Firewire' => 'FW',
				'Billentyűzet világítás' => 'Backlit KB',
				'Multitouch képesség' => 'Multitouch',
				'Töltő' => 'AC',
				'Fordulatszám' => 'HDD Seb.',
				'Mobil hálózat' => 'HSPDA',
				'Express card' => 'ExpressCard',
				'Dokkolható' => 'Dokk',
				'E-sata' => 'eSata',
				'Display port' => 'DP',
				'Mikrofon bemenet' => 'Mikrofon be',
				'Automata fényerőszabályzó' => '-',
				'Numerikus billentyűzet' => 'Num. bill.',
				'Pointstick' => '-',
				'GPS' => 'GPS',
				'Tpm modul' => 'TPM',
				'Kijelző 3D képesség' => 'TFT 3D',
				'Első háttértár' => 'HDD 1',
				'Második háttértár' => 'HDD 2',
				'Második tipusa' => 'HDD 2 Típus',
				'S/PDIF' => 'SPDIF',
			),
			'opt_list' => array('Ár' => 1),
		),
		'laptophu' => array(
			'domain' => 'http://www.laptop.hu',
			'url' => 'http://www.laptop.hu/laptopok?page=%d',
			'url_count' => 0, //22,
			'url_start' => 0,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => 'h2 a.teaser-title',
			'nb_name' => '.termek-title a',
			'opt_names' => '.technikai-jellemzok-tablazat li',
			'opt_name_split' => ':',
			'opt_values' => '',
			'price_field' => '.netto-ar span',
			'price_mul' => 1,
			'translit' => array(
				'Család' => 'Modell',
				'Sorozat' => 'Sorozat',
				'Processzor' => 'CPU',
				'Memória' => 'RAM',
				'Merevlemez' => 'HDD',
				'Kijelző' => 'TFT',
				'Videokártya' => 'GPU',
				'Operációs rendszer' => 'OS',
				'Optikai meghajtó' => 'ODD',
				'Szín' => 'Szín',
				'Jótállás' => 'Gar.',
				'LAN' => 'LAN',
				'USB' => 'USB',
				'Kártyaolvasó' => 'CR',
				'Akkumulátor' => 'Akku',
				'Wireless LAN' => 'WLAN',
				'Billentyűzet' => 'Bill.',
				'Pozicionáló eszköz' => 'Touchpad',
				'Használt/Új' => 'Állapot',
				'Webkamera' => 'Kamera',
				'Numerikus billentyűzet' => 'Num. bill',
				'Egyéb portok' => 'Portok',
				'Bluetooth' => 'BT',
				'Dokkcsatlakozó' => 'Dokk',
				'TV-kimenet' => 'TV ki',
				'Modem' => 'Modem',
				'Firewire' => 'FW',
				'Soros port' => 'RS232',
				'Párhuzamos port' => 'LPT',
				'PCMCIA' => 'PCMCIA',
				'HDMI' => 'HDMI',
				'e-SATA' => 'eSata',
				'Ujjlenyomat-olvasó' => 'FP',
			),
			'opt_list' => array('Ár' => 1),
		),
		'notebookhu' => array(
			'domain' => '',
			'url' => 'http://www.notebook.hu/notebook.html?limit=30&p=%d',
			'url_count' => 1, //12,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.product-name a',
			'nb_name' => '.product-name h1',
			'opt_names' => '.data-table .label',
			'opt_values' => '.data-table .data',
			'price_field' => '.price',
			'price_mul' => 1/1.27,
			'translit' => array(
				'Processzor' => 'CPU',
				'Chipkészlet' => 'Chipset',
				'Memória' => 'RAM',
				'Merevlemez' => 'HDD',
				'Kijelző méret' => 'TFT',
				'Grafikus vezérlő' => 'GPU',
				'Optikai meghajtó' => 'ODD',
				'Operációs rendszer' => 'OS',
				'LAN' => '',
				'WLAN' => '',
				'Bluetooth' => 'BT',
				'HSDPA' => '',
				'Videó' => 'TV ki',
				'Audió' => 'Audio',
				'VGA port' => 'VGA ki',
				'HDMI port' => 'HDMI',
				'Display port' => 'DP',
				'Ujjlenyomat olvasó' => 'FP',
				'Webkamera' => 'Kamera',
				'Kártyaolvasó' => 'CR',
				'Express Card Reader' => 'ExpressCard',
				'Firewire' => 'FW',
				'E-SATA' => 'eSata',
				'USB port' => 'USB',
				'Akkumulátor' => 'Akku',
				'Extrák' => 'Egyéb',
				'Súly' => '',
				'Méret' => '',
				'Garancia' => 'Gar.',
			),
			'opt_list' => array('Ár' => 1),
		),
		'supernotebook' => array(
			'domain' => 'http://www.supernotebook.hu/',
			'url' => 'http://www.supernotebook.hu/notebook_es_kiegeszitok/notebook?page=%d',
			'url_count' => 1, //20,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.list_prouctname a',
			'nb_name' => '.center h1',
			'opt_names' => '.parameter_table td:nth-child(1)',
			'opt_values' => '.parameter_table td:nth-child(2)',
			'price_field' => '.price_row .postfix',
			'price_mul' => 1,
			'translit' => array(
				'Képernyő átmérő' => 'TFT Méret',
				'Kijelző Típusa' => 'TFT Típus',
				'Felbontás' => 'TFT Felb.',
				'Videokártya' => 'GPU',
				'Processzor' => 'CPU',
				'Memória' => 'RAM',
				'Mem. Típus' => 'RAM Típus',
				'Merevlemez' => 'HDD',
				'Optikai meghajtó' => 'ODD',
				'Operációs rendszer.' => 'OS',
				'Akkumulátor' => 'Akku',
				'LAN' => 'LAN',
				'Wireless (Wifi)' => 'WLAN',
				'Modem' => '',
				'USB 2.0' => 'USB',
				'USB 3.0' => 'USB 3',
				'Ujjlenyomat-olvasó' => 'FP',
				'Beépített kártyaolvasó' => 'CR',
				'Bluetooth.' => 'BT',
				'Firewire port' => 'FW',
				'Hangszóró' => 'Audio',
				'Kamera felbontás' => 'Kamera',
				'TV-Tuner' => 'TV in',
				'Video csatlakozás' => 'VGA ki',
				'Egyéb csatlakozás' => 'Portok',
				'HSDPA, WWAN, UMTS' => 'HSPDA',
				'Súly' => '',
				'Szín' => '',
				'Dokkolhatóság' => 'Dokk',
				'Szállított szoftverek' => 'Szoftverek',
				'Kiegészítők' => 'Tartozék',
				'Billentyűzet megvilágítás' => 'Backlit KB',
				'További információ' => 'Info',
				'Garancia' => 'Gar.',
				'Busz' => '',
				'Chipset gyártó' => 'Chipset',
				'DirectX' => 'GPU DX',
				'D-Sub' => 'VGA ki',
				'Dual VGA' => 'VGA Dual',
				'DVI' => 'DVI-D',
				'Kiszerelés' => '',
				'Hűtés típusa' => 'CPU Hűtés',
				'Mag órajel' => 'CPU Frek.',
				'Mem. Bus' => 'CPU FSB',
				'Mem. órajel' => 'RAM Frek.',
				'Sebesség' => '',
				'OpenGL' => 'GPU OGL',
				'SLI/CrossFire támogatás' => 'GPU SLI',
				'VGA Chipset' => 'CGA Chip',
				'TV-out' => 'TV ki',
				'Egyéb' => '',
			),
			'opt_list' => array('Ár' => 1),
		),
		'notebooksarok' => array(
			'domain' => 'http://www.notebooksarok.hu/',
			'url' => 'http://www.notebooksarok.hu/?p=%d',
			'url_count' => 1, //22,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.prod_title a',
			'nb_name' => '.product_name',
			'opt_names' => '.feature_table td:nth-child(1)',
			'opt_values' => '.feature_table td:nth-child(2)',
			'price_field' => '.product_price',
			'price_mul' => 1,
			'translit' => array(
				'Kijelző' => 'TFT',
				'Processzor' => 'CPU',
				'RAM' => '',
				'Belső tároló' => 'HDD',
				'Grafikus vezérlő' => 'GPU',
				'Szoftver' => 'OS',
				'Hálózat' => 'LAN',
				'I/O interfész' => 'Portok',
				'Kamera' => '',
				'Muntimédia' => 'Audio',
				'Akkumulátor' => 'Akku',
				'Súly / Méret' => 'Súly',
				'Egyéb' => '',
				'Szín' => '',
				'Garancia' => 'Gar.',
				'Merevlemez (HDD)' => 'HDD +',
				'Optikai meghajtó (ODD)' => 'ODD',
			),
			'opt_list' => array('Ár' => 1),
		),
		'notebookspecialista' => array(
			'domain' => 'http://www.notebookspecialista.hu/',
			'url' => 'http://www.notebookspecialista.hu/?id=termekek&markak[]=1&markak[]=443&markak[]=6&markak[]=5&markak[]=2&markak[]=3&markak[]=4&markak[]=225&markak[]=1003&markak[]=1282&markak[]=1293&markak[]=257&markak[]=1891&mlsz=1&al_menu=notebook_kereso&e=1',
			'url_count' => 1, //1,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => 'a.termeknev',
			'nb_name' => '.termeknev:first',
			'opt_names' => '.termek_reszletes_fejlec',
			'opt_values' => '.termek_leiras',
			'price_field' => '#ar.ajanlott4',
			'price_mul' => 1,
			'translit' => array(
				'Ajánlatunk' => '-',
				'Extrák' => 'Egyéb',
				'Leírás' => '-',
				'Processzor' => 'CPU',
				'Chipkészlet' => 'Chipset',
				'Memória' => 'RAM',
				'Kijelző' => 'TFT',
				'Winchester' => 'HDD',
				'Optikai meghajtó' => 'ODD',
				'Videókártya' => 'GPU',
				'Hangkártya' => 'Audio',
				'Billentyűzet' => 'Bill.',
				'Akku/üzemidő' => 'Akku',
				'Interfész' => 'Portok',
				'Multimédia' => '',
				'Operációs rendszer' => 'OS',
				'Irodai programcsomag' => '-',
				'Vírusvédelem' => '-',
				'Szolgáltatás' => '-',
				'Súly' => '',
				'Méret' => '',
				'Garancia' => 'Gar.',
				'Szállítás' => '-',
			),
			'opt_list' => array('Ár' => 1),
		),
		'notebookzone' => array( ====== FIXME !
			'domain' => 'http://www.notebookzone.hu/',
			'url' => 'http://www.notebookzone.hu/adv_search.php?gy1=TOSHIBA&gy2=HP&gy3=ASUS&gy5=DELL&gy6=ACER&gy7=LENOVO&gy8=SAMSUNG&gy9=SONY&gy10=PACKARD%%20BELL&gy12=FUJITSU&gy13=MSI&gy14=APPLE&cpu0=atom&cpu1=core%%20duo&cpu2=core%%202&cpu3=i3&cpu4=i5&cpu5=i7&cpu6=amd&k10=10&k11=11&k12=12&k13=13&k14=14&k15=15&k16=16&k17=17&k18=18&op=mindegy&vga=mindegy&smin=1&smax=1000',
			'url_count' => 1, //1,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.font5_link',
			'nb_name' => '.akcio td td a.font5',
			'opt_names' => '.akcio tr[bgcolor="#ffffff"] .font3[bgcolor="#efefef"]',
			'opt_values' => '.akcio tr[bgcolor="#ffffff"] .font2',
			'price_field' => '.font3_red_10:first',
			'price_mul' => 1,
			'translit' => array(
			),
			'opt_list' => array('Ár' => 1),
		),*/
		'laptopnotebooknetbook' => array(
			'domain' => 'http://www.laptop-notebook-netbook.hu/',
			'url' => 'http://www.laptop-notebook-netbook.hu/acer-laptop-termekek/acer-laptop/?p=%d',
			'url_count' => 0, //18,
			'url_start' => 0,
			'list_post' => array(
			),
			'list_post_counter' => '',
			'list_item' => '.product h2 a',
			'nb_name' => '#product_big h1.green-grad',
			'price_field' => '.price .ptop:first',
			'opt_names' => '.head',
			'opt_values' => '.desc',
			'price_mul' => 1,
			'translit' => array(
			),
			'opt_list' => array('Ár' => 1),
		),

	);

	alog('Go');

	$limit = 0;
	foreach ($shops as $shopname => $data) {
		$notebook = array();
		$opt_list = $data['opt_list'];

		for ($j = isset($data['url_start']) ? $data['url_start'] : 1; $j <= $data['url_count']; $j++) {
			alog('Get list: ' . $shopname);
			$url = sprintf($data['url'], $j);
			if (count($data['list_post']) && isset($data['list_post_counter'])) {
				$data['list_post'][$data['list_post_counter']] = $j;
			}
			$list = curl_get_cache($url, $shopname . '-' . $j . '-list.html', $data['list_post']);
			alog('List size: ' . strlen($list));

			$listhtml = phpQuery::newDocument($list);
			$r = $listhtml[$data['list_item']];
			foreach ($r as $row) {

				if ($limit++ >= 10) { $limit = 0; break; }

				$nb = curl_get_cache($data['domain'] . pq($row)->attr('href'), basename_c(pq($row)->attr('href')));
				$nbhtml = phpQuery::newDocument($nb);

				$tds = $nbhtml['#p01 tr'];
				foreach ($tds as $td) {
					if (pq($td)->find('.spectit')->length > 0) {
						$pre = pq($td)->find('.spectit')->html();
					}
					if (pq($td)->find('.tjName')->length > 0) {
						pq($td)->find('.tjName')->text($pre . ' ' . pq($td)->find('.tjName')->html());
					}
				}

				$tds = $nbhtml['.PARAMETER tr'];
				foreach ($tds as $td) {
					if (pq($td)->is('.first')) {
						$pre = pq($td)->find('.name')->html();
					}
					if (pq($td)->find('.name')->length > 0) {
						pq($td)->find('.name')->text(clean($pre . ' ' . pq($td)->find('.name')->html()));
					}
				}

				$tds = $nbhtml['.specifikacio > li'];
				foreach ($tds as $td) {
					if (pq($td)->find('ul .nsCrMatmod')->length > 0) {
						$pre = clean(pq($td)->find('> .nsCrMatmod')->text());
						$lis = pq($td)->find('ul .nsCrMatmod');
						foreach ($lis as $li) {
							pq($li)->text(clean($pre . ' ' . pq($li)->text()));
						}
					}
				}

				$nbname = clean($nbhtml[$data['nb_name']]->text());
				// /acer-laptop-termekek/acer-netbook/acer-aspire-one-d270-6cellas-fekete-3g-mar-2-ev-garanciaval/2040/?xjxfun=showSpec&xjxargs[]=N2040
				$opt_name = array_map('clean', $nbhtml[$data['opt_names']]->getString());
				if (isset($data['opt_name_split'])) {
					foreach ($opt_name as &$aggr) {
						list ($name, $value) = preg_split('/ *' . $data['opt_name_split'] . ' */', $aggr);
						$aggr = $name;
						$opt_value[] = $value;
					}
				} else {
					$opt_value = array_map('clean', $nbhtml[$data['opt_values']]->getString());
				}
				print_r($opt_name);
				print_r($opt_value);

				for ($i = 0; $i <= count($opt_name); $i++) {
					if ($opt_name[$i] && $opt_value[$i]) {
						if (array_key_exists($opt_name[$i], $data['translit']) && $data['translit'][$opt_name[$i]]) {
							$opt_name2 = $data['translit'][$opt_name[$i]];
						} else {
							$opt_name2 = preg_replace('/:$/', '', $opt_name[$i]);
						}
						$notebook[$shopname][$nbname][$opt_name2] = preg_replace(array('/[\r\n]|:$/', '/\t/'), array('', ' '), $opt_value[$i]);
						$opt_list[$opt_name2] = 1;
					}
				}

				$notebook[$shopname][$nbname]['Ár'] = round(preg_replace('/[^0-9]/', '', $nbhtml[$data['price_field']]->text()) * $data['price_mul']);
			}
		}

		foreach ($notebook as $sname => $data) {
			$f = fopen($sname . '.csv', 'w');
			foreach ($opt_list as $option => $temp) {
				fputs($f, sprintf("\t%s", $option));
			}
			fputs($f, sprintf("\n"));
			foreach ($data as $nbname => $props) {
				fputs($f, sprintf("%s", $nbname));
				foreach ($opt_list as $option => $temp) {
					fputs($f, sprintf("\t%s", $props[$option]));
				}
				fputs($f, sprintf("\n"));
			}
			fclose($f);
		}
	}

	alog('End');
