#!/usr/bin/php
<?php

	require('phpQuery.php');
	define('TESTING', 1);
	define('DEBUG', 1);

	class helper {
		public static function log($str) {
			if (is_array($str)) {
				$str = print_r($str, true);
			}
			if (defined('DEBUG') && DEBUG) {
				printf("[%s] %s\n", date('Y-m-d H:i:s'), $str);
			}
		}

		public static function clean($str) {
			if (is_array($str)) {
				foreach ($str as &$s) {
					$s = helper::clean($s);
				}
				$ret = $str;
			} else {
				$ret = trim(preg_replace(array('/[\r\n]|:$| $/', '/[\t ]+/'), array('', ' '), $str));
			}
			return $ret;
		}

		public static function basenameClean($str) {
			return preg_replace('/[\?\&\:]/', '_', basename($str));
		}
	}

	class downloader {
		private $cachePath = 'cache';
		private $userAgent = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.4 (KHTML, like Gecko) Chrome/22.0.1229.94 Safari/537.4';

		function getCacheDirectory() {
			return $this->cachePath;
		}

		function getCache($url, $filename, $post = array()) {
			$myFile = $this->cachePath . DIRECTORY_SEPARATOR . $filename;

			if (file_exists($myFile)) {
				helper::log('Cache hit: ' . $myFile);
				$ret = file_get_contents($myFile);
			} else {
				helper::log('Downloading to: ' . $myFile);
				$c = curl_init();
				curl_setopt($c, CURLOPT_URL, $url);
				curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($c, CURLOPT_USERAGENT, $this->userAgent);
				curl_setopt($c, CURLOPT_REFERER, preg_replace('/(http:\/\/.*?\/).*/', '$1', $url));
				if (count($post)) {
					curl_setopt($c, CURLOPT_POSTFIELDS, $post);
				}
				$ret = curl_exec($c);
				curl_close($c);

				file_put_contents($myFile, $ret);
			}

			return preg_replace('/<\?xml .*\?>/im', '', $ret);
		}
	}

	class shop {
		/**
		 * shop name, private identifier, naming base
		 */
		protected $shopName = '';
		/**
		 * domain name for fetching individual products details page
		 */
		protected $shopDomain = '';
		/**
		 * product listing url (usually a search result page or main category)
		 */
		protected $shopListUrl = '';
		/**
		 * strip the GET parameters from product urls
		 */
		protected $shopProductUrlStripGet = 0;
		/**
		 * amount of result pages (if limited to a number of products)
		 */
		protected $shopListUrlCount = 1;
		/**
		 * start of page listing
		 */
		protected $shopListUrlCountStart = 1;
		/**
		 * if search query needs a list of POST parameters
		 */
		protected $shopListPostData = array();
		/**
		 * name of the field in the POST parameteres, appended to $shopListPostData
		 */
		protected $shopListPostCounterField = '';
		/**
		 * a phpquery selector for getting a product's url, must contain href
		 */
		protected $shopPqListNode = '';
		/**
		 * a phpquery selector for getting product's name from details page
		 */
		protected $shopPqProductName = '';
		/**
		 * a phpquery selector for getting product's price from details page
		 */
		protected $shopPqPriceField = '';
		/**
		 * price multiplier, eg. div by 100, tax correction, currency conversion
		 */
		protected $shopPriceMultiplier = 1;
		/**
		 * a phpquery selector for parameter names
		 */
		protected $shopPqParameterNames = '';
		/**
		 * a phpquery selector for parameter values
		 */
		protected $shopPqParameterValues = '';
		/**
		 * transliterate site's fixed parameter names to custom
		 */
		protected $shopTransliterateParameters = array();
		/**
		 * sorting parameter names in result
		 */
		protected $shopParameterOrder = array('TermékNév' => 1, 'Ár' => 1);

		private $shopListFiles = array();
		private $productUrls = array();
		private $products = array();
		private $productParameters = array();
		private $productValues = array();

		function __construct() {
			date_default_timezone_set('Europe/Budapest');
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', shop name: ' . $this->shopName);
			$this->downloader = new downloader();
		}

		function getList() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', from: ' . $this->shopListUrlCountStart . ', count: ' . $this->shopListUrlCount);
			@mkdir($this->downloader->getCacheDirectory() . DIRECTORY_SEPARATOR . $this->shopName);
			for ($listIterator = $this->shopListUrlCountStart; $listIterator <= $this->shopListUrlCount; $listIterator++) {
				$fileName = sprintf("%s" . DIRECTORY_SEPARATOR . "%s-%02d", $this->shopName, $this->shopName, $listIterator);

				helper::log(__FUNCTION__ . ': ' . sprintf($this->shopListUrl, $listIterator) . ' -> ' . $fileName);
				if (count($this->shopListPostData)) {
					$this->shopListPostData[$this->shopListPostCounterField] = $listIterator;
				}
				$listPage = $this->downloader->getCache(sprintf($this->shopListUrl, $listIterator), $fileName, $this->shopListPostData);
				$this->addList($listPage);

				$this->shopListFiles[] = $fileName;
			}
		}

		private function addList($html) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', size: ' . strlen($html));
			$listHtml = phpQuery::newDocument($html);
			$links = $listHtml[$this->shopPqListNode];
			foreach ($links as $link) {
				$this->productUrls[] = pq($link)->attr('href');
			}
		}

		function getProducts() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', amount: ' . count($this->productUrls));
			foreach ($this->productUrls as $productUrl) {
				if (defined('TESTING') && TESTING) {
					if ($i++ > 10) {
						break;
					}
				}

				if ($this->shopProductUrlStripGet) {
					$productUrl = preg_replace('/\?.*/', '', $productUrl);
				}
				$productHtml = $this->downloader->getCache($this->shopDomain . $productUrl, $this->shopName . DIRECTORY_SEPARATOR . helper::basenameClean($productUrl));
				$this->parseProduct($productHtml);
			}
		}

		function prepareProduct(&$product) {
		}

		function getParamsAndValues($product) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this));

			$myParams = $product[$this->shopPqParameterNames];
			$myValues = $product[$this->shopPqParameterValues];

			$this->productParameters = helper::clean(pq($myParams)->getString());
			$this->productValues = pq($myValues)->getString();
			$params = array();

			for ($paramIterator = 0; $paramIterator < count($this->productParameters); $paramIterator ++) {
				if (array_key_exists($this->productParameters[$paramIterator], $this->shopTransliterateParameters) && $this->shopTransliterateParameters[$this->productParameters[$paramIterator]]) {
					$param = $this->shopTransliterateParameters[$this->productParameters[$paramIterator]];
				} else {
					$param = $this->productParameters[$paramIterator];
				}
				$params[$param] = helper::clean($this->productValues[$paramIterator]);
			}

			return $params;
		}

		private function parseProduct($html) {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', size: ' . strlen($html));
			$product = phpQuery::newDocument($html);
			$this->prepareProduct($product);

			$productName = helper::clean($product[$this->shopPqProductName]->text());
			$productPrice = helper::clean($product[$this->shopPqPriceField]->text());

			$this->products[$productName]['TermékNév'] = $productName;
			$this->products[$productName]['Ár'] = round(preg_replace('/[^0-9]/', '', $productPrice) * $this->shopPriceMultiplier) . ' Ft';

			$params = $this->getParamsAndValues($product);

			foreach ($params as $parameter => $value) {
				if (!array_key_exists($parameter, $this->shopParameterOrder)) {
					$this->shopParameterOrder[$parameter] = 1;
				}
				$this->products[$productName][$parameter] = $value;
			}
		}

		function formatCSV() {
			$ret = '';

			if (isset($this->shopParameterOrder['-'])) {
				unset($this->shopParameterOrder['-']);
			}

			$ret .= implode("\t", array_keys($this->shopParameterOrder)) . "\n";
			foreach ($this->products as $product => $productSpec) {
				foreach ($this->shopParameterOrder as $paramName => $exists) {
					$ret .= sprintf("%s\t", $productSpec[$paramName]);
				}
				$ret .= "\n";
			}
			return $ret;
		}

		function writeCSV() {
			file_put_contents($this->shopName . '.csv', $this->formatCSV());
		}

		function getData() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this));
			$this->getList();
			$this->getProducts();
			$this->writeCSV();
		}
	}

	class shopNotebookhu extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'notebookhu';
		protected $shopDomain                  = '';
		protected $shopListUrl                 = 'http://www.notebook.hu/notebook.html?limit=30&p=%d';
		protected $shopListUrlCount            = 1; // 22
		protected $shopPqListNode              = '.product-name a';
		protected $shopPqProductName           = '.product-name h1';
		protected $shopPqPriceField            = '.price:first';
		protected $shopPriceMultiplier         = 0.7874;
		protected $shopPqParameterNames        = '.data-table .label';
		protected $shopPqParameterValues       = '.data-table .data';
		protected $shopTransliterateParameters = array(
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
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);
	}

	class shopNotebookspecialista extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'notebookspecialista';
		protected $shopDomain                  = 'http://www.notebookspecialista.hu/';
		protected $shopListUrl                 = 'http://www.notebookspecialista.hu/?id=termekek&markak[]=1&markak[]=443&markak[]=6&markak[]=5&markak[]=2&markak[]=3&markak[]=4&markak[]=225&markak[]=1003&markak[]=1282&markak[]=1293&markak[]=257&markak[]=1891&mlsz=1&al_menu=notebook_kereso&e=1';
		protected $shopListUrlCount            = 1;
		protected $shopProductUrlStripGet      = 1;
		protected $shopPqListNode              = 'a.termeknev';
		protected $shopPqProductName           = '.termeknev:first';
		protected $shopPqPriceField            = '#ar.ajanlott4';
		protected $shopPriceMultiplier         = 1;
		protected $shopPqParameterNames        = '#jellemzok_td .termek_reszletes_fejlec';
		protected $shopPqParameterValues       = '#jellemzok_td span.termek_leiras';
		protected $shopTransliterateParameters = array(
			'Ajánlatunk:' => '-',
			'Extrák' => '-',
			'Leírás' => '-',
			'Gyártó' => '-',
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
			'Szállítás' => '',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
			$product['select']->remove();
			$product['img']->remove();
			$product['script']->remove();
		}
	}

	class shopSupernotebook extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'supernotebook';
		protected $shopDomain                  = 'http://www.supernotebook.hu/';
		protected $shopListUrl                 = 'http://www.supernotebook.hu/notebook_es_kiegeszitok/notebook?page=%d';
		protected $shopListUrlCount            = 1; // 20
		protected $shopPqListNode              = '.list_prouctname a';
		protected $shopPqProductName           = '.center h1';
		protected $shopPqPriceField            = '.price_row .postfix';
		protected $shopPriceMultiplier         = 1;
		protected $shopPqParameterNames        = '.parameter_table td:nth-child(1)';
		protected $shopPqParameterValues       = '.parameter_table td:nth-child(2)';
		protected $shopTransliterateParameters = array(
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
			'HSDPA, WWAN, UMTS' => 'HSDPA',
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
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Típus' => 1,
			'TFT Felb.' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'RAM Típus' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
		}
	}

	class shopNotebookstore extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'notebookstore';
		protected $shopDomain                  = 'http://notebookstore.hu/Notebook/';
		protected $shopListUrl                 = 'http://notebookstore.hu/kereses/pag_cikkcsoport.aspx?ccsop=COMNOT&ps=90&p=%d';
		protected $shopListUrlCount            = 1; // 6
		protected $shopPqListNode              = '.nsCikkNev a';
		protected $shopPqProductName           = '#myPrint h1';
		protected $shopPqPriceField            = '#ctl00_CON_default_UCO_CikkReszletek1_RPT_reszletek_ctl00_PAN_reszl div:contains("Fizet")';
		protected $shopPriceMultiplier         = 0.7874;
		protected $shopPqParameterNames        = '.nsCrMatmod';
		protected $shopPqParameterValues       = '.nsCrMatmodErtek';
		protected $shopTransliterateParameters = array(
			'Termék osztály' => 'Kategória',
			'Gyártói ajánlás' => 'Egyéb',
			'Processzor' => 'CPU',
			'ProcesszorGyártó' => 'CPU Gyártó',
			'ProcesszorCsalád' => 'CPU Család',
			'ProcesszorMagok száma' => 'CPU Magok',
			'ProcesszorÓrajel' => 'CPU Frek.',
			'ProcesszorTurbóórajel' => 'CPU Turbo',
			'ProcesszorSpeed step' => 'CPU SS',
			'ProcesszorCache' => 'CPU Cache',
			'ProcesszorMax memória' => 'CPU RAM Max',
			'Processzor64 bit támogatás' => 'CPU 64',
			'ProcesszorTechnológia' => 'CPU Tech',
			'ProcesszorMax fogyasztás' => 'CPU Wh',
			'ProcesszorVirtualizáció' => 'CPU Virt',
			'ProcesszorHyperthreading' => 'CPU HT',
			'Kijelző' => 'TFT',
			'KijelzőSzabvány' => 'TFT Szabány',
			'KijelzőKépátló' => 'TFT Méret',
			'KijelzőFelület' => 'TFT Méret +',
			'KijelzőFelbontás' => 'TFT Felb.',
			'KijelzőVizszintes felbontás' => 'TFT X',
			'KijelzőFüggőleges felbontás' => 'TFT Y',
			'KijelzőKéparány' => 'TFT Arány',
			'KijelzőTechnológia' => 'TFT Típus',
			'KijelzőÉrintő' => 'TFT Touch',
			'KijelzőBeláthatóság' => 'TFT Fok',
			'KijelzőSzínhű' => 'TFT Színhű',
			'Videó vezérlő' => 'GPU',
			'Videó vezérlőGyártó' => 'GPU Gyártó',
			'Videó vezérlőCsalád' => 'GPU Család',
			'Videó vezérlőTipusa' => 'GPU Típus',
			'Videó vezérlőSaját memória' => 'GPU Mem',
			'Videó vezérlőMemória tipus' => 'GPU Mem Típus',
			'Videó vezérlőTechnológia' => 'GPU Tech',
			'Videó vezérlőDirect x' => 'GPU DX',
			'Videó vezérlőShader model' => 'GPU Shader',
			'Videó vezérlőOpen gl' => 'GPU OGL',
			'Memória' => 'RAM',
			'MemóriaMemória mérete' => 'RAM Méret',
			'MemóriaMemória tipusa' => 'RAM Típus',
			'Háttértár' => 'HDD',
			'Első tipusa' => 'HDD Típus',
			'Optikai meghajtó' => 'ODD',
			'Optikai meghajtóElhelyezkedés' => 'ODD Hely',
			'Optikai meghajtóCd képesség' => 'ODD CD',
			'Optikai meghajtóDvd képesség' => 'ODD DVD',
			'Optikai meghajtóBlueray képesség' => 'ODD BR',
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
			'Billentyűzet nyelv' => 'Bill.',
			'Billentyűzet szine' => 'Bill. Szín',
			'Gombok száma' => 'Bill. Gombok',
			'Fingerprint' => 'FP',
			'Kensington' => '',
			'Akkumulátor' => 'Akku',
			'AkkumulátorCellák száma' => 'Akku Cella',
			'AkkumulátorTeljesítmény' => 'Akku Wh',
			'AkkumulátorTipusa' => 'Akku Típus',
			'AkkumulátorMax üzemidő' => 'Akku Idő',
			'Operációs rendszer' => 'OS',
			'Operációs rendszerCsalád' => 'OS Család',
			'Operációs rendszerTipus' => 'OS Típus',
			'Operációs rendszerNyelv' => 'OS Nyelv',
			'Operációs rendszerBitszám' => 'OS 32/64',
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
			'Fordulatszám' => 'HDD RPM',
			'Mobil hálózat' => 'HSDPA',
			'Express card' => 'ExpressCard',
			'Dokkolható' => 'Dokk',
			'E-sata' => 'eSata',
			'Display port' => 'DP',
			'Mikrofon bemenet' => 'Mikrofon be',
			'Automata fényerőszabályzó' => '-',
			'Numerikus billentyűzet' => 'Bill. Num',
			'Pointstick' => '-',
			'GPS' => 'GPS',
			'Tpm modul' => 'TPM',
			'Kijelző3D képesség' => 'TFT 3D',
			'Első háttértár' => 'HDD',
			'Második háttértár' => 'HDD 2',
			'Második tipusa' => 'HDD 2 Típus',
			'S/PDIF' => 'SPDIF',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'TFT Szabány' => 1,
			'TFT Méret' => 1,
			'TFT Méret +' => 1,
			'TFT Felb.' => 1,
			'TFT X' => 1,
			'TFT Y' => 1,
			'TFT Arány' => 1,
			'TFT Típus' => 1,
			'TFT Touch' => 1,
			'TFT Fok' => 1,
			'TFT Színhű' => 1,
			'CPU' => 1,
			'CPU Gyártó' => 1,
			'CPU Család' => 1,
			'CPU Magok' => 1,
			'CPU Frek.' => 1,
			'CPU Turbo' => 1,
			'CPU SS' => 1,
			'CPU Cache' => 1,
			'CPU RAM Max' => 1,
			'CPU 64' => 1,
			'CPU Tech' => 1,
			'CPU Wh' => 1,
			'CPU Virt' => 1,
			'CPU HT' => 1,
			'RAM' => 1,
			'RAM Méret' => 1,
			'RAM Típus' => 1,
			'HDD' => 1,
			'HDD Típus' => 1,
			'HDD 2' => 1,
			'HDD 2 Típus' => 1,
			'GPU' => 1,
			'GPU Gyártó' => 1,
			'GPU Család' => 1,
			'GPU Típus' => 1,
			'GPU Mem' => 1,
			'GPU Mem Típus' => 1,
			'GPU Tech' => 1,
			'GPU DX' => 1,
			'GPU Shader' => 1,
			'GPU OGL' => 1,
			'Súly' => 1,
			'Akku' => 1,
			'Akku Cella' => 1,
			'Akku Wh' => 1,
			'Akku Típus' => 1,
			'Akku Idő' => 1,
			'ODD' => 1,
			'ODD Hely' => 1,
			'ODD CD' => 1,
			'ODD DVD' => 1,
			'ODD BR' => 1,
			'OS' => 1,
			'OS Család' => 1,
			'OS Típus' => 1,
			'OS Nyelv' => 1,
			'OS 32/64' => 1,
			'Modell' => 1,
			);

		function prepareProduct(&$product) {
			$product['#ctl00_CON_default_UCO_CikkReszletek1_RPT_reszletek_ctl00_PAN_reszl div:contains("Fizet") script']->remove();
			$myList = $product['ul.specifikacio li'];
			foreach ($myList as $listItem) {
				if (pq($listItem)->find('ul')->length > 0) {
					$pre = helper::clean(pq($listItem)->find('> .nsCrMatmod')->text());
					$subList = pq($listItem)->find('li .nsCrMatmod');
					foreach ($subList as $subItem) {
						pq($subItem)->html($pre . pq($subItem)->html());
					}
				}
			}
		}
	}

	// notebookzone: fail, nem konzekvensen hasznaljak a css osztalyokat, nem lehet 1:1 osszerendelni a prop:val parokat

	class shopNotebooksarok extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'notebooksarok';
		protected $shopDomain                  = 'http://www.notebooksarok.hu/';
		protected $shopListUrl                 = 'http://www.notebooksarok.hu/?p=%d';
		protected $shopListUrlCount            = 1; // 22
		protected $shopPqListNode              = '.prod_title a';
		protected $shopPqProductName           = '.product_name';
		protected $shopPqPriceField            = '.product_price';
		protected $shopPriceMultiplier         = 1;
		protected $shopPqParameterNames        = '.feature_table td:nth-child(1)';
		protected $shopPqParameterValues       = '.feature_table td:nth-child(2)';
		protected $shopTransliterateParameters = array(
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
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'HDD +' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
		}
	}

	class shopUsanotebook extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'usanotebook';
		protected $shopDomain                  = 'http://www.usanotebook.hu/';
		protected $shopListUrl                 = 'http://www.usanotebook.hu/laptop/index.php?mode=useprm&tplDt=subcat|%d||&prmID=3;;;;;';
		protected $shopPqListNode              = '.tboxPRtit a';
		protected $shopPqProductName           = 'h1 a';
		protected $shopPqPriceField            = '.prcBr, .prcNtAkc.BD span';
		protected $shopPqParameterNames        = '.tjName';
		protected $shopPqParameterValues       = '.tjValue';
		protected $shopTransliterateParameters = array(
			'Általános jellemzők Felhasználás' => 'Kategória',
			'Általános jellemzők Típus' => 'Típus',
			'Általános jellemzők Állapot' => 'Állapot',
			'Általános jellemzők Szín' => 'Szín',
			'Általános jellemzők Akciós termék' => 'Akció',
			'Processzor Gyártó' => 'CPU Gyártó',
			'Processzor Család' => 'CPU Család',
			'Processzor Típus' => 'CPU Típus',
			'Processzor Paraméterek' => 'CPU +',
			'Memória Méret' => 'RAM Méret',
			'Memória Frekvencia' => 'RAM Frek.',
			'Memória Jellemzők' => 'RAM Jell.',
			'Merevlemez Méret' => 'HDD Méret',
			'Merevlemez Tipus' => 'HDD Típus',
			'Merevlemez Jellemzők' => 'HDD Jell.',
			'Optikai meghajtó Típus' => 'ODD',
			'Kijelző Méret' => 'TFT Méret',
			'Kijelző Felbontás' => 'TFT Felb.',
			'Kijelző Típus' => 'TFT Típus',
			'Kijelző Jellemzők' => 'TFT Jell.',
			'Videókártya Gyártó' => 'GPU Gyártó',
			'Videókártya Típus' => 'GPU Típus',
			'Videókártya Jelleg' => 'GPU Jelleg',
			'Akkumulátor Jellemzők' => 'Akku',
			'Egyéb technikai paraméretek Chipkészlet' => 'Chipset',
			'Egyéb technikai paraméretek Billentyűzet nyelve' => 'Nyelv',
			'Egyéb technikai paraméretek Audio' => 'Audio',
			'Egyéb technikai paraméretek Portok/egyebek' => 'Portok',
			'Egyéb technikai paraméretek Port jellemzők' => 'Portok +',
			'Egyéb technikai paraméretek Bővítőhelyek' => 'ExpressCard',
			'Egyéb technikai paraméretek Extrák' => 'Tartozék',
			'Egyéb technikai paraméretek Hálózat' => 'LAN',
			'Egyéb technikai paraméretek Hálózati jellemzők' => 'LAN +',
			'Operációs rendszer Típus' => 'OS',
			'Fizikai paraméterek Méret' => 'Méret',
			'Fizikai paraméterek Súly' => 'Súly',
			'Garancia Időtartam' => 'Gar.',
			'Garancia Típus' => 'Gar. Típus',
			'Egyéb technikai paraméretek Mutató eszköz' => 'Touchpad',
			'Egyéb szoftverek Gyártói szoftverek' => 'Egyéb',
			'Egyéb technikai paraméretek Billentyűzet jellemzői' => 'Bill.',
			'Fizikai paraméterek Szín' => 'Szín',
			'Operációs rendszer Jellemzők' => 'OS Jell.',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'TFT Típus' => 1,
			'TFT Jell.' => 1,
			'CPU Gyártó' => 1,
			'CPU Család' => 1,
			'CPU Típus' => 1,
			'CPU +' => 1,
			'RAM Méret' => 1,
			'RAM Frek.' => 1,
			'RAM Jell.' => 1,
			'HDD Méret' => 1,
			'HDD Típus' => 1,
			'HDD Jell.' => 1,
			'GPU Gyártó' => 1,
			'GPU Típus' => 1,
			'GPU Jelleg' => 1,
			'GPU Jell.' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Gar. Típus' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'Chipset' => 1,
			);

		function prepareProduct(&$product) {
			$tds = $product['#p01 tr'];
			foreach ($tds as $td) {
				if (pq($td)->find('.spectit')->length > 0) {
					$pre = pq($td)->find('.spectit')->html();
				}
				if (pq($td)->find('.tjName')->length > 0) {
					pq($td)->find('.tjName')->text($pre . ' ' . pq($td)->find('.tjName')->html());
				}
			}
		}
	}

	class shopLaptopnotebooknetbook extends shop {
		/* shop reviewed 2012-10-18 */
		protected $shopName                    = 'laptopnotebooknetbook';
		protected $shopDomain                  = 'http://www.laptop-notebook-netbook.hu/';
		protected $shopListUrl                 = 'http://www.laptop-notebook-netbook.hu/acer-laptop-termekek/acer-laptop/?p=%d';
		protected $shopListUrlCount            = 0; // 18
		protected $shopListUrlCountStart       = 0;
		protected $shopPqListNode              = '.product h2 a';
		protected $shopPqProductName           = '#product_big h1.green-grad';
		protected $shopPqPriceField            = '.price .ptop:first';
		protected $shopPriceMultiplier         = 1;
		protected $shopPqParameterNames        = '.head';
		protected $shopPqParameterValues       = '.desc';
		protected $shopTransliterateParameters = array(
			'Termék neve' => '-',
			'Operációs rendszer' => 'OS',
			'Processzor' => 'CPU',
			'Memória' => 'RAM',
			'SSD' => '',
			'HDD' => '',
			'Kijelző mérete' => 'TFT Méret',
			'Kijelző felbontása' => 'TFT Felb.',
			'Kijelző leírása' => 'TFT Jell',
			'VGA típusa' => 'GPU',
			'Hangrendszer' => '-',
			'Kommunikáció' => 'LAN',
			'Akkumulátor, üzemidő' => 'Akku',
			'Bemeneti/Kimeneti csatlakozók' => 'Portok',
			'Billentyűzet' => 'Bill.',
			'Súly és méretek' => 'Súly',
			'Szoftver' => '-',
			'Garancia' => 'Gar.',
			'Optikai egység' => 'ODD',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'TFT Jell' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'SSD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
			$product['.printing']->remove();
			$href = $product['#pt_0']->attr('href');
			preg_match('/\/([0-9]+)\/$/', $href, $r);
			$product_id = $r[1];
			helper::log($product_id);
			$newdoc = $this->downloader->getCache($this->shopDomain . '/acer-laptop-termekek/?xjxfun=showSpec&xjxargs[]=' . $product_id, $this->shopName . DIRECTORY_SEPARATOR . 'spec-' . $product_id);
			$domdoc = new DOMDocument();
			$domdoc->loadXML($newdoc);
			$product->append($domdoc->getElementsByTagName('cmd')->item(0)->nodeValue);
		}
	}

	class shopLaptophu extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'laptophu';
		protected $shopDomain                  = 'http://www.laptop.hu';
		protected $shopListUrl                 = 'http://www.laptop.hu/laptopok?page=%d';
		protected $shopListUrlCount            = 0; // 22
		protected $shopListUrlCountStart       = 0;
		protected $shopPqListNode              = 'h2 a.teaser-title';
		protected $shopPqProductName           = '.termek-title a';
		protected $shopPqPriceField            = '.netto-ar span';
		protected $shopPriceMultiplier         = 1;
		protected $shopPqParameterNames        = '.shop_parameter';
		protected $shopPqParameterValues       = '.shop_ertek';
		protected $shopTransliterateParameters = array(
			'Processzor' => 'CPU',
			'Memória' => 'RAM',
			'Merevlemez' => 'HDD',
			'Kijelző' => 'TFT',
			'Videokártya' => 'GPU',
			'Operációs rendszer' => 'OS',
			'Optikai meghajtó' => 'ODD',
			'Szín' => '',
			'Jótállás' => 'Gar.',
			'Bluetooth' => 'BT',
			'Express Card' => 'ExpressCard',
			'Kártyaolvasó' => 'CR',
			'USB' => '',
			'Használt/Új' => 'Állapot',
			'Billentyűzet' => 'Bill.',
			'Pozicionáló eszköz' => 'Touchpad',
			'Modem' => '',
			'LAN' => '',
			'Wireless LAN' => 'WLAN',
			'Egyéb portok' => 'Portok',
			'Család' => '',
			'Sorozat' => 'Modell',
			'Akkumulátor' => 'Akku',
			'Webkamera' => 'Kamera',
			'Numerikus billentyűzet' => 'Bill. Num',
			'Dokkcsatlakozó' => 'Dokk',
			'TV-kimenet' => 'TV ki',
			'Firewire' => 'FW',
			'Soros port' => 'RS232',
			'Párhuzamos port' => 'LPT',
			'PCMCIA' => '',
			'HDMI' => '',
			'e-SATA' => 'eSata',
			'Ujjlenyomat-olvasó' => 'FP',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'ODD' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
			$spec = $product['.technikai-jellemzok-tablazat li'];
			foreach ($spec as $specLine) {
				pq($specLine)->html('<div class=\'shop_parameter\'>' .
					implode('</div><div class=\'shop_ertek\'>', preg_split('/:/', pq($specLine)->text())) .
					'</div>'
				);
			}
		}
	}

	class shopMacropolis extends shop {
		/* shop reviewed 2012-10-20 */
		protected $shopName                    = 'macropolis';
		protected $shopDomain                  = 'http://www.macropolis.hu';
		protected $shopListUrl                 = 'http://www.macropolis.hu/index.php?kat=1&k_kategoria=notebook';
		protected $shopListUrlCount            = 1;
		protected $shopPqListNode              = '.termek_nev .start_ikon_fejlec';
		protected $shopPqProductName           = '.h2_cim';
		protected $shopPqPriceField            = '.termek_ar_netto';
		protected $shopPqParameterNames        = '.adatlap_bal';
		protected $shopPqParameterValues       = '.adatlap';
		protected $shopTransliterateParameters = array(
			'Processzor típus' => 'CPU Típus',
			'Processzor frekvencia' => 'CPU Frek.',
			'Memória mérete' => 'RAM Méret',
			'Memória típusa' => 'RAM Típus',
			'Merevlemez mérete' => 'HDD Méret',
			'Merevlemez sebessége' => 'HDD Seb.',
			'Optikai meghajtó' => 'ODD',
			'Floppy' => '-',
			'Kijelző méret' => 'TFT Méret',
			'Kijelző felbontás' => 'TFT Felb.',
			'Videókártya típusa' => 'GPU Típus',
			'Videókártya mérete' => 'GPU RAM',
			'Hangkártya' => 'Audio',
			'Hálózati kártya' => 'LAN',
			'WLAN kártya' => 'WLAN',
			'Modem' => '',
			'Portok' => '',
			'Kártyaolvasó' => 'CR',
			'Akkumulátor' => 'Akku',
			'Tömeg' => 'Súly',
			'Billentyűzet' => 'Bill.',
			'Szoftver' => 'OS',
			'Garancia' => 'Gar.',
			'Garancia-kiterjesztés(ek)' => '-',
			'Extrák' => 'Egyéb',
			'Állapot' => '',
			'Méret' => '',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'CPU Típus' => 1,
			'CPU Frek.' => 1,
			'RAM Méret' => 1,
			'RAM Típus' => 1,
			'HDD Méret' => 1,
			'HDD Seb.' => 1,
			'GPU Típus' => 1,
			'GPU RAM' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
		}
	}

	class shopAsusnotebook extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'asusnotebook';
		protected $shopDomain                  = 'http://asusnotebook.hu';
		protected $shopListUrl                 = 'http://asusnotebook.hu/termekek/search_q=%%25;lista=1/name=ASC?egylapon=600';
		protected $shopPqListNode              = '.listazas_content h1 a[href!="#"]';
		protected $shopPqProductName           = '.content_title';
		protected $shopPqPriceField            = '.eredeti_ar_brutto2:first span';
		protected $shopPqParameterNames        = '.tulajd_name, .kibont_name';
		protected $shopPqParameterValues       = '.tulajd_ertek, .kibont_ertek';
		protected $shopTransliterateParameters = array(
			'Operációs rendszer' => 'OS',
			'Processzor' => 'CPU',
			'Memória' => 'RAM',
			'Merevlemez' => 'HDD',
			'Optikai meghajtók' => 'ODD',
			'Kijelző' => 'TFT',
			'Grafikus kártya' => 'GPU',
			'Mikrofon' => '',
			'Hangszoró' => 'Audio',
			'Webkamera' => 'Kamera',
			'W-lan / Wifi' => 'WLAN',
			'VGA csatlakozó' => 'VGA ki',
			'USB csatlakozók' => 'USB',
			'HDMI csatlakozó' => 'HDMI',
			'Kártyaolvasó' => 'CR',
			'Nyelvezet' => 'Nyelv',
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
			'Bluetooth' => 'BT',
			'Grafikus memória mérete' => 'GPU Mem',
			'Mini D-SUB csatlakozó' => 'Portok',
			'Képernyő méret' => 'TFT Méret',
			'Felbontás' => 'TFT Felb.',
			'CPU' => '',
			'HDD' => '',
			'RAM' => '',
			'VGA' => 'VGA ki',
			'WLAN' => '',
			'USB' => '',
			'HDMI' => '',
			'LAN (RJ45)' => 'LAN',
			'Adapter' => 'AC',
			'Nettó súly' => 'Súly',
			'Megnevezés' => '-'
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'CPU' => 1,
			'CPU Gyártó' => 1,
			'CPU Típus' => 1,
			'CPU Seb.' => 1,
			'RAM' => 1,
			'RAM Típus' => 1,
			'HDD' => 1,
			'HDD Típus' => 1,
			'GPU' => 1,
			'GPU Típus' => 1,
			'GPU Mem' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			);
	}

	class shopToshibabolt extends shop {
		/* shop reviewed 2012-10-20 */
		protected $shopName                    = 'toshibabolt';
		protected $shopDomain                  = 'http://www.toshibabolt.hu/';
		protected $shopListUrl                 = 'http://www.toshibabolt.hu/index.php/laptop.html?limit=all';
		protected $shopPqListNode              = '.item .name a';
		protected $shopPqProductName           = '.product-info-box .value-text .name';
		protected $shopPqPriceField            = '.netto .price';
		protected $shopPqParameterNames        = 'div.description label';
		protected $shopPqParameterValues       = 'div.description div:not(.description-title)';
		protected $shopTransliterateParameters = array(
			'Operációs rendszer' => 'OS',
			'CPU' => '',
			'Képernyő' => 'TFT',
			'HDD' => '',
			'RAM' => '',
			'Garancia' => 'Gar.',
			'Méret' => '',
			'Wifi™' => 'WLAN',
			'USB port' => 'USB',
			'Akku cella' => 'Akku',
			'Express card' => 'ExpressCard',
			'HDMI' => '',
			'HSUPA' => 'HSUPA',
			'Bluetooth™' => 'BT',
			'Szín' => '',
			'Ujjlenyomat' => 'FP',
			'IEE1394 port' => 'FW',
			'Súly' => '',
			'Memória olvasó' => 'CR',
			'Beépített kamera' => 'Kamera',
			'Beépített mikrofon' => 'Mikrofon',
			'SZÍN' => 'Szín',
			'S-video' => 'SVIDEO',
			'Arcfelismerő szoftver' => '-',
			'RJ-45 port' => 'LAN',
			'DVD' => 'ODD',
			'Blu-ray' => 'ODD BR',
			'HSDPA' => 'HSDPA',
			'Memória kártya' => 'CR',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'ODD BR' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {

		}
	}

	class shopLaptopszalon extends shop {
		/* shop reviewed 2012-10-17 */
		protected $shopName                    = 'laptopszalon';
		protected $shopDomain                  = '';
		protected $shopListUrl                 = 'http://www.laptopszalon.hu/webshop/search';
		protected $shopListUrlCount            = 1; // 37
		protected $shopListPostData            = array('ajax' => 'true', 'modul' => 'webshop', 'mode' => 'search', 'cat' => 'laptop_notebook', 'by' => 'asc', 'order' => 'action');
		protected $shopListPostCounterField    = 'pg';
		protected $shopPqListNode              = '.name > a';
		protected $shopPqProductName           = '.prodtitle:first';
		protected $shopPqPriceField            = '.product_price';
		protected $shopPqParameterNames        = '.PARAMETER .name:not([colspan="2"])';
		protected $shopPqParameterValues       = '.PARAMETER tr td > div';
		protected $shopTransliterateParameters = array(
			'Gyártó: Gyártó' => 'Gyártó',
			'Gyártó: Család' => 'Család',
			'Gyártó: Modell' => 'Modell',
			'Processzor típus: Processzor típus' => 'CPU Típus',
			'Processzor típus: Számozás' => 'CPU Szám',
			'Processzor típus: Mag neve' => 'CPU Mag',
			'Memória méret: Memória méret' => 'RAM Méret',
			'Memória méret: Frekvencia' => 'RAM Frek.',
			'Merevlemez méret: Merevlemez méret' => 'HDD Méret',
			'Merevlemez méret: Tipus' => 'HDD Típus',
			'Kijelző méret: Kijelző méret' => 'TFT Méret',
			'Kijelző méret: Felbontás' => 'TFT Felb.',
			'Kijelző méret: tipus' => 'TFT Típus',
			'Videókártya gyártó: Videókártya gyártó' => 'GPU Gyártó',
			'Videókártya gyártó: jellege' => 'GPU Jell.',
			'Videókártya gyártó: Tipus' => 'GPU Típus',
			'Operációs rendszer: Operációs rendszer' => 'OS',
			'Operációs rendszer: Nyelv' => 'OS Nyelv',
			'Operációs rendszer: Bit' => 'OS 32/64',
			'Operációs rendszer: Család' => 'OS Család',
			'Garancia időtartam: Garancia időtartam' => 'Gar.',
			'Garancia időtartam: Tipus' => 'Gar. Típus',
			'Biztosítás: Biztosítás' => 'Biztosítás',
			'<br> Csatlakozók (adatátviteli eszközök): <br> Csatlakozók (adatátviteli eszközök)' => 'Portok',
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
			'<br> Csatlakozók (adatátviteli eszközök): Webkamera' => 'Kamera',
			'<br> Csatlakozók (adatátviteli eszközök): HSDPA' => 'HSDPA',
			'<br> Egyéb jellemzők: <br> Egyéb jellemzők' => 'Egyéb',
			'<br> Egyéb jellemzők: Optikai meghajtó típus' => 'ODD',
			'<br> Egyéb jellemzők: Akkumulátor cellaszám' => 'Akku cella',
			'<br> Egyéb jellemzők: Billentyűzet' => 'Bill.',
			'<br> Egyéb jellemzők: Szin' => 'Szín',
			'<br> Egyéb jellemzők: Súly' => 'Súly',
			'<br> Egyéb jellemzők: Kensington zár' => 'Kensington',
			'<br> Egyéb jellemzők: <span class="first">Egyéb:</span>' => 'Egyéb +',
			'Kijelző méret: Képarány' => 'TFT Arány',
			'<br> Egyéb jellemzők: jellemző' => 'Egyéb ++',
			'<br> Egyéb jellemzők: Audio' => 'Audio',
			'<br> Egyéb jellemzők: Chipkészlet' => 'Chipset',
			'<br> Egyéb jellemzők: Ujjlenyomat olvasó' => 'FP',
			'<br> Egyéb jellemzők: Jellemző' => 'Egyéb ++',
			'<br> Egyéb jellemzők: Hangszóró' => 'Hangszóró',
			'<br> Egyéb jellemzők: Egyéb információk' => 'Egyéb +++',
			'Videókártya gyártó: RAM mérete' => 'GPU Mem',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'TFT Típus' => 1,
			'TFT Arány' => 1,
			'CPU Típus' => 1,
			'CPU Szám' => 1,
			'CPU Mag' => 1,
			'RAM Méret' => 1,
			'RAM Frek.' => 1,
			'HDD Méret' => 1,
			'HDD Típus' => 1,
			'GPU Gyártó' => 1,
			'GPU Jell.' => 1,
			'GPU Típus' => 1,
			'GPU Mem' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Gar. Típus' => 1,
			'Akku cella' => 1,
			'ODD' => 1,
			'Chipset' => 1,
			);

		function prepareProduct(&$product) {
			$tds = $product['.PARAMETER tr'];
			foreach ($tds as $td) {
				pq($td)->find('.osopcio')->remove();
				if (pq($td)->is('.first')) {
					$pre = pq($td)->find('.name')->html();
				}
				if (pq($td)->find('.name')->length > 0) {
					pq($td)->find('.name')->text(helper::clean($pre . ' ' . pq($td)->find('.name')->html()));
				}
			}
		}
	}

	class shopIpon extends shop {
		/* shop reviewed 2012-10-20 */
		protected $shopName                    = 'ipon';
		protected $shopDomain                  = 'http://ipon.hu';
		protected $shopListUrl                 = 'http://ipon.hu/webshop/group/notebook/439/?listlimit=700';
		protected $shopListUrlCount            = 1;
		protected $shopPqListNode              = '.webshop_item_list .item_header .item_name a';
		protected $shopPqProductName           = '.c_product_name';
		protected $shopPqPriceField            = '.akciosar span';
		protected $shopPqParameterNames        = '.shop_parameter';
		protected $shopPqParameterValues       = '.shop_ertek';
		protected $shopTransliterateParameters = array(
			'PROCESSZOR' => 'CPU',
			'MEMÓRIA MÉRET' => 'RAM Méret',
			'MEMÓRIA TÍPUS' => 'RAM Típus',
			'KÉPERNYŐ ÁTLÓ' => 'TFT Méret',
			'FELBONTÁS' => 'TFT Felb.',
			'LED KIJELZŐ' => 'TFT LED',
			'INTEGRÁLT VGA TÍPUS' => 'GPU',
			'MEREVLEMEZ (HDD)' => 'HDD',
			'OPTIKAI MEGHAJTÓ' => 'ODD',
			'OPERÁCIÓS RENDSZER' => 'OS',
			'WLAN (WIFI)' => 'WLAN',
			'VEZETÉKES HÁLÓZAT' => 'LAN',
			'BLUETOOTH' => 'BT',
			'USB PORT' => 'USB',
			'eSATA' => 'eSata',
			'FIREWIRE /IEEE1394/' => 'FW',
			'KAMERA' => 'Kamera',
			'KÁRTYAOLVASÓ' => 'CR',
			'HDMI' => '',
			'MINI HDMI' => 'MiniHDMI',
			'MICRO HDMI' => 'MicroHDMI',
			'DISPLAYPORT' => 'DP',
			'MINI DISPLAYPORT' => 'MiniDP',
			'LOKALIZÁCIÓ' => 'Nyelv',
			'SÚLY' => 'Súly',
			'USB PORT (3.0)' => 'USB 3',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Felb.' => 1,
			'TFT LED' => 1,
			'CPU' => 1,
			'RAM Méret' => 1,
			'RAM Típus' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
			$prodname = $product['a.lightbox img']->attr('alt');
			$product->append('<div class="c_product_name">' . $prodname . '</div>');
			$product['.infobox:contains("Átvétel")']->remove();
			$spec = $product['.infobox .speclista1 li'];
			foreach ($spec as $specLine) {
				pq($specLine)->html('<div class=\'shop_parameter\'>' .
					implode('</div><div class=\'shop_ertek\'>', preg_split('/:/', pq($specLine)->text())) .
					'</div>'
				);
			}
		}
	}

	class shopBluechip extends shop {
		/* shop reviewed 2012-10-26 */
		protected $shopName                    = 'bluechip';
		protected $shopDomain                  = 'http://www.bluechip.hu';
		protected $shopListUrl                 = 'http://www.bluechip.hu/termekek/notebook';
		protected $shopListUrlCount            = 1;
		protected $shopPqListNode              = '.listname b a';
		protected $shopPqProductName           = 'h1.black14bold';
		protected $shopPqPriceField            = '#pricebox .gray10';
		protected $shopPqParameterNames        = '#properties_box tr td:nth-child(1)';
		protected $shopPqParameterValues       = '#properties_box tr td:nth-child(2)';
		protected $shopTransliterateParameters = array(
			'Felhasználási terület' => 'Kategória',
			'Processzor' => 'CPU',
			'Chipkészlet' => 'Chipset',
			'Kijelző' => 'TFT',
			'3D kijelző' => 'TFT 3D',
			'Memória' => 'RAM',
			'Szabad memória hely' => 'RAM Slot',
			'Merevlemez' => 'HDD',
			'Optikai meghajtó' => 'ODD',
			'Operációs rendszer' => 'OS',
			'Hangkártya' => 'Audio',
			'Mikrofon' => 'Mikrofon',
			'Videokártya' => 'GPU',
			'Webkamera' => 'Kamera',
			'Wi-Fi' => 'WLAN',
			'Bluetooth' => 'BT',
			'3G' => 'HSDPA',
			'Csatlakozó' => 'Portok',
			'Lan' => 'LAN',
			'USB 3.0' => 'USB 3',
			'Akkumulátor' => 'Akku',
			'Méret' => '',
			'Súly' => '',
			'Garancia' => 'Gar.',
			'USB' => '',
			'Extra SSD' => 'SSD',
			'Memóriakártya olvasó' => 'CR',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT' => 1,
			'TFT 3D' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'RAM Slot' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
		}
	}

	class shopEdigital extends shop {
		/* shop reviewed 2012-10-26 */
		protected $shopName                    = 'edigital';
		protected $shopDomain                  = 'http://www.edigital.hu/';
		protected $shopListUrl                 = 'http://www.edigital.hu/Informatika/Notebook_netbook_ultrabook-c2410.html?dosearch=0&cat=2410&cat2410%%5Btxt%%5D=&cat2410%%5Bpagenumber%%5D=%d&cat2410%%5Blimit%%5D=500';
		protected $shopListUrlCount            = 0;
		protected $shopListUrlCountStart       = 0;
		protected $shopPqListNode              = '.felsorolas_termeknev a';
		protected $shopPqProductName           = '.ed_product_available h1';
		protected $shopPqPriceField            = '.price #price';
		protected $shopPqParameterNames        = '.spec p label';
		protected $shopPqParameterValues       = '.spec p strong';
		protected $shopTransliterateParameters = array(
			'Gyártó' => '',
			'Processzor típus' => 'CPU Típus',
			'Processzor frekvencia' => 'CPU Frek.',
			'Processzor FSB frekvencia' => 'CPU FSB',
			'Chipkészlet' => 'Chipset',
			'Memória' => 'RAM',
			'Memóriahelyek száma' => 'RAM Slot',
			'Memória max. bővíthetőség' => 'RAM Max',
			'Merevlemez kapacitás' => 'HDD Méret',
			'Merevlemez sebesség' => 'HDD Seb.',
			'Kijelző méret' => 'TFT Méret',
			'Kijelző típusa' => 'TFT Típus',
			'Kijelző felbontás' => 'TFT Felb.',
			'Grafikus vezérlő' => 'GPU',
			'Grafikus vezérlő memória' => 'GPU Mem',
			'Optikai meghajtó' => 'ODD',
			'Hálózat' => 'LAN',
			'Csatlakozók' => 'Portok',
			'Audio csatlakozók' => 'Audio',
			'Kártyaolvasó' => 'CR',
			'Operációs rendszer' => 'OS',
			'Operációs rendszer kompatibilitás' => 'OS +',
			'Akkumulátor típus' => 'Akku Típus',
			'Akkumulátor teljesítmény' => 'Akku Telj.',
			'Akkumulátor cellaszám' => 'Akku Cella',
			'Billentyűzet kiosztás' => 'Bill.',
			'Szín' => '',
			'Méret (HxMxSz)' => 'Méret',
			'Súly' => '',
			'Tartozékok' => 'Egyéb',
			'Extrák' => 'Egyéb +',
			'Garancia' => 'Gar.',
			);
		protected $shopParameterOrder          = array(
			'TermékNév' => 1,
			'Ár' => 1,
			'TFT Méret' => 1,
			'TFT Típus' => 1,
			'TFT Felb.' => 1,
			'CPU Típus' => 1,
			'CPU Frek.' => 1,
			'CPU FSB' => 1,
			'RAM' => 1,
			'RAM Slot' => 1,
			'RAM Max' => 1,
			'HDD Méret' => 1,
			'HDD Seb.' => 1,
			'GPU' => 1,
			'GPU Mem' => 1,
			'Súly' => 1,
			'Gar.' => 1,
			'Akku Típus' => 1,
			'Akku Telj.' => 1,
			'Akku Cella' => 1,
			'ODD' => 1,
			'OS' => 1,
			'OS +' => 1,
			);

		function prepareProduct(&$product) {
		}
	}

	//

	// TFT, CPU, RAM, HDD, GPU, Súly, Gar., Akku, ODD, OS

	$shop = new shopEdigital();
	$shop->getData();
