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
		protected $shopParameterOrder = array('Term??kN??v' => 1, '??r' => 1);

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

			$this->products[$productName]['Term??kN??v'] = $productName;
			$this->products[$productName]['??r'] = round(preg_replace('/[^0-9]/', '', $productPrice) * $this->shopPriceMultiplier) . ' Ft';

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
			'Chipk??szlet' => 'Chipset',
			'Mem??ria' => 'RAM',
			'Merevlemez' => 'HDD',
			'Kijelz?? m??ret' => 'TFT',
			'Grafikus vez??rl??' => 'GPU',
			'Optikai meghajt??' => 'ODD',
			'Oper??ci??s rendszer' => 'OS',
			'LAN' => '',
			'WLAN' => '',
			'Bluetooth' => 'BT',
			'HSDPA' => '',
			'Vide??' => 'TV ki',
			'Audi??' => 'Audio',
			'VGA port' => 'VGA ki',
			'HDMI port' => 'HDMI',
			'Display port' => 'DP',
			'Ujjlenyomat olvas??' => 'FP',
			'Webkamera' => 'Kamera',
			'K??rtyaolvas??' => 'CR',
			'Express Card Reader' => 'ExpressCard',
			'Firewire' => 'FW',
			'E-SATA' => 'eSata',
			'USB port' => 'USB',
			'Akkumul??tor' => 'Akku',
			'Extr??k' => 'Egy??b',
			'S??ly' => '',
			'M??ret' => '',
			'Garancia' => 'Gar.',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'Aj??nlatunk:' => '-',
			'Extr??k' => '-',
			'Le??r??s' => '-',
			'Gy??rt??' => '-',
			'Processzor' => 'CPU',
			'Chipk??szlet' => 'Chipset',
			'Mem??ria' => 'RAM',
			'Kijelz??' => 'TFT',
			'Winchester' => 'HDD',
			'Optikai meghajt??' => 'ODD',
			'Vide??k??rtya' => 'GPU',
			'Hangk??rtya' => 'Audio',
			'Billenty??zet' => 'Bill.',
			'Akku/??zemid??' => 'Akku',
			'Interf??sz' => 'Portok',
			'Multim??dia' => '',
			'Oper??ci??s rendszer' => 'OS',
			'Irodai programcsomag' => '-',
			'V??rusv??delem' => '-',
			'Szolg??ltat??s' => '-',
			'S??ly' => '',
			'M??ret' => '',
			'Garancia' => 'Gar.',
			'Sz??ll??t??s' => '',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'K??perny?? ??tm??r??' => 'TFT M??ret',
			'Kijelz?? T??pusa' => 'TFT T??pus',
			'Felbont??s' => 'TFT Felb.',
			'Videok??rtya' => 'GPU',
			'Processzor' => 'CPU',
			'Mem??ria' => 'RAM',
			'Mem. T??pus' => 'RAM T??pus',
			'Merevlemez' => 'HDD',
			'Optikai meghajt??' => 'ODD',
			'Oper??ci??s rendszer.' => 'OS',
			'Akkumul??tor' => 'Akku',
			'LAN' => 'LAN',
			'Wireless (Wifi)' => 'WLAN',
			'Modem' => '',
			'USB 2.0' => 'USB',
			'USB 3.0' => 'USB 3',
			'Ujjlenyomat-olvas??' => 'FP',
			'Be??p??tett k??rtyaolvas??' => 'CR',
			'Bluetooth.' => 'BT',
			'Firewire port' => 'FW',
			'Hangsz??r??' => 'Audio',
			'Kamera felbont??s' => 'Kamera',
			'TV-Tuner' => 'TV in',
			'Video csatlakoz??s' => 'VGA ki',
			'Egy??b csatlakoz??s' => 'Portok',
			'HSDPA, WWAN, UMTS' => 'HSDPA',
			'S??ly' => '',
			'Sz??n' => '',
			'Dokkolhat??s??g' => 'Dokk',
			'Sz??ll??tott szoftverek' => 'Szoftverek',
			'Kieg??sz??t??k' => 'Tartoz??k',
			'Billenty??zet megvil??g??t??s' => 'Backlit KB',
			'Tov??bbi inform??ci??' => 'Info',
			'Garancia' => 'Gar.',
			'Busz' => '',
			'Chipset gy??rt??' => 'Chipset',
			'DirectX' => 'GPU DX',
			'D-Sub' => 'VGA ki',
			'Dual VGA' => 'VGA Dual',
			'DVI' => 'DVI-D',
			'Kiszerel??s' => '',
			'H??t??s t??pusa' => 'CPU H??t??s',
			'Mag ??rajel' => 'CPU Frek.',
			'Mem. Bus' => 'CPU FSB',
			'Mem. ??rajel' => 'RAM Frek.',
			'Sebess??g' => '',
			'OpenGL' => 'GPU OGL',
			'SLI/CrossFire t??mogat??s' => 'GPU SLI',
			'VGA Chipset' => 'CGA Chip',
			'TV-out' => 'TV ki',
			'Egy??b' => '',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT T??pus' => 1,
			'TFT Felb.' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'RAM T??pus' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'Term??k oszt??ly' => 'Kateg??ria',
			'Gy??rt??i aj??nl??s' => 'Egy??b',
			'Processzor' => 'CPU',
			'ProcesszorGy??rt??' => 'CPU Gy??rt??',
			'ProcesszorCsal??d' => 'CPU Csal??d',
			'ProcesszorMagok sz??ma' => 'CPU Magok',
			'Processzor??rajel' => 'CPU Frek.',
			'ProcesszorTurb????rajel' => 'CPU Turbo',
			'ProcesszorSpeed step' => 'CPU SS',
			'ProcesszorCache' => 'CPU Cache',
			'ProcesszorMax mem??ria' => 'CPU RAM Max',
			'Processzor64 bit t??mogat??s' => 'CPU 64',
			'ProcesszorTechnol??gia' => 'CPU Tech',
			'ProcesszorMax fogyaszt??s' => 'CPU Wh',
			'ProcesszorVirtualiz??ci??' => 'CPU Virt',
			'ProcesszorHyperthreading' => 'CPU HT',
			'Kijelz??' => 'TFT',
			'Kijelz??Szabv??ny' => 'TFT Szab??ny',
			'Kijelz??K??p??tl??' => 'TFT M??ret',
			'Kijelz??Fel??let' => 'TFT M??ret +',
			'Kijelz??Felbont??s' => 'TFT Felb.',
			'Kijelz??Vizszintes felbont??s' => 'TFT X',
			'Kijelz??F??gg??leges felbont??s' => 'TFT Y',
			'Kijelz??K??par??ny' => 'TFT Ar??ny',
			'Kijelz??Technol??gia' => 'TFT T??pus',
			'Kijelz????rint??' => 'TFT Touch',
			'Kijelz??Bel??that??s??g' => 'TFT Fok',
			'Kijelz??Sz??nh??' => 'TFT Sz??nh??',
			'Vide?? vez??rl??' => 'GPU',
			'Vide?? vez??rl??Gy??rt??' => 'GPU Gy??rt??',
			'Vide?? vez??rl??Csal??d' => 'GPU Csal??d',
			'Vide?? vez??rl??Tipusa' => 'GPU T??pus',
			'Vide?? vez??rl??Saj??t mem??ria' => 'GPU Mem',
			'Vide?? vez??rl??Mem??ria tipus' => 'GPU Mem T??pus',
			'Vide?? vez??rl??Technol??gia' => 'GPU Tech',
			'Vide?? vez??rl??Direct x' => 'GPU DX',
			'Vide?? vez??rl??Shader model' => 'GPU Shader',
			'Vide?? vez??rl??Open gl' => 'GPU OGL',
			'Mem??ria' => 'RAM',
			'Mem??riaMem??ria m??rete' => 'RAM M??ret',
			'Mem??riaMem??ria tipusa' => 'RAM T??pus',
			'H??tt??rt??r' => 'HDD',
			'Els?? tipusa' => 'HDD T??pus',
			'Optikai meghajt??' => 'ODD',
			'Optikai meghajt??Elhelyezked??s' => 'ODD Hely',
			'Optikai meghajt??Cd k??pess??g' => 'ODD CD',
			'Optikai meghajt??Dvd k??pess??g' => 'ODD DVD',
			'Optikai meghajt??Blueray k??pess??g' => 'ODD BR',
			'H??l??zat' => 'LAN',
			'Vezet??k n??lk??li h??l??zat' => 'WLAN',
			'Bluetooth' => 'BT',
			'K??rtyaolvas??' => 'CR',
			'??ssz USB' => 'USB',
			'VGA' => 'VGA ki',
			'HDMI' => 'HDMI',
			'Hangkimenet' => 'Audio',
			'Be??p??tett mikrofon' => 'Mikrofon',
			'Be??p??tett webkamera' => 'Kamera',
			'Billenty??zet nyelv' => 'Bill.',
			'Billenty??zet szine' => 'Bill. Sz??n',
			'Gombok sz??ma' => 'Bill. Gombok',
			'Fingerprint' => 'FP',
			'Kensington' => '',
			'Akkumul??tor' => 'Akku',
			'Akkumul??torCell??k sz??ma' => 'Akku Cella',
			'Akkumul??torTeljes??tm??ny' => 'Akku Wh',
			'Akkumul??torTipusa' => 'Akku T??pus',
			'Akkumul??torMax ??zemid??' => 'Akku Id??',
			'Oper??ci??s rendszer' => 'OS',
			'Oper??ci??s rendszerCsal??d' => 'OS Csal??d',
			'Oper??ci??s rendszerTipus' => 'OS T??pus',
			'Oper??ci??s rendszerNyelv' => 'OS Nyelv',
			'Oper??ci??s rendszerBitsz??m' => 'OS 32/64',
			'T??meg' => 'S??ly',
			'X m??ret' => 'Sz??les',
			'Y m??ret' => 'M??ly',
			'Z m??ret' => 'Magas',
			'Modell' => 'Modell',
			'Modem' => 'Modem',
			'USB 3-k sz??ma' => 'USB 3',
			'Firewire' => 'FW',
			'Billenty??zet vil??g??t??s' => 'Backlit KB',
			'Multitouch k??pess??g' => 'Multitouch',
			'T??lt??' => 'AC',
			'Fordulatsz??m' => 'HDD RPM',
			'Mobil h??l??zat' => 'HSDPA',
			'Express card' => 'ExpressCard',
			'Dokkolhat??' => 'Dokk',
			'E-sata' => 'eSata',
			'Display port' => 'DP',
			'Mikrofon bemenet' => 'Mikrofon be',
			'Automata f??nyer??szab??lyz??' => '-',
			'Numerikus billenty??zet' => 'Bill. Num',
			'Pointstick' => '-',
			'GPS' => 'GPS',
			'Tpm modul' => 'TPM',
			'Kijelz??3D k??pess??g' => 'TFT 3D',
			'Els?? h??tt??rt??r' => 'HDD',
			'M??sodik h??tt??rt??r' => 'HDD 2',
			'M??sodik tipusa' => 'HDD 2 T??pus',
			'S/PDIF' => 'SPDIF',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'TFT Szab??ny' => 1,
			'TFT M??ret' => 1,
			'TFT M??ret +' => 1,
			'TFT Felb.' => 1,
			'TFT X' => 1,
			'TFT Y' => 1,
			'TFT Ar??ny' => 1,
			'TFT T??pus' => 1,
			'TFT Touch' => 1,
			'TFT Fok' => 1,
			'TFT Sz??nh??' => 1,
			'CPU' => 1,
			'CPU Gy??rt??' => 1,
			'CPU Csal??d' => 1,
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
			'RAM M??ret' => 1,
			'RAM T??pus' => 1,
			'HDD' => 1,
			'HDD T??pus' => 1,
			'HDD 2' => 1,
			'HDD 2 T??pus' => 1,
			'GPU' => 1,
			'GPU Gy??rt??' => 1,
			'GPU Csal??d' => 1,
			'GPU T??pus' => 1,
			'GPU Mem' => 1,
			'GPU Mem T??pus' => 1,
			'GPU Tech' => 1,
			'GPU DX' => 1,
			'GPU Shader' => 1,
			'GPU OGL' => 1,
			'S??ly' => 1,
			'Akku' => 1,
			'Akku Cella' => 1,
			'Akku Wh' => 1,
			'Akku T??pus' => 1,
			'Akku Id??' => 1,
			'ODD' => 1,
			'ODD Hely' => 1,
			'ODD CD' => 1,
			'ODD DVD' => 1,
			'ODD BR' => 1,
			'OS' => 1,
			'OS Csal??d' => 1,
			'OS T??pus' => 1,
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
			'Kijelz??' => 'TFT',
			'Processzor' => 'CPU',
			'RAM' => '',
			'Bels?? t??rol??' => 'HDD',
			'Grafikus vez??rl??' => 'GPU',
			'Szoftver' => 'OS',
			'H??l??zat' => 'LAN',
			'I/O interf??sz' => 'Portok',
			'Kamera' => '',
			'Muntim??dia' => 'Audio',
			'Akkumul??tor' => 'Akku',
			'S??ly / M??ret' => 'S??ly',
			'Egy??b' => '',
			'Sz??n' => '',
			'Garancia' => 'Gar.',
			'Merevlemez (HDD)' => 'HDD +',
			'Optikai meghajt?? (ODD)' => 'ODD',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'HDD +' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'??ltal??nos jellemz??k Felhaszn??l??s' => 'Kateg??ria',
			'??ltal??nos jellemz??k T??pus' => 'T??pus',
			'??ltal??nos jellemz??k ??llapot' => '??llapot',
			'??ltal??nos jellemz??k Sz??n' => 'Sz??n',
			'??ltal??nos jellemz??k Akci??s term??k' => 'Akci??',
			'Processzor Gy??rt??' => 'CPU Gy??rt??',
			'Processzor Csal??d' => 'CPU Csal??d',
			'Processzor T??pus' => 'CPU T??pus',
			'Processzor Param??terek' => 'CPU +',
			'Mem??ria M??ret' => 'RAM M??ret',
			'Mem??ria Frekvencia' => 'RAM Frek.',
			'Mem??ria Jellemz??k' => 'RAM Jell.',
			'Merevlemez M??ret' => 'HDD M??ret',
			'Merevlemez Tipus' => 'HDD T??pus',
			'Merevlemez Jellemz??k' => 'HDD Jell.',
			'Optikai meghajt?? T??pus' => 'ODD',
			'Kijelz?? M??ret' => 'TFT M??ret',
			'Kijelz?? Felbont??s' => 'TFT Felb.',
			'Kijelz?? T??pus' => 'TFT T??pus',
			'Kijelz?? Jellemz??k' => 'TFT Jell.',
			'Vide??k??rtya Gy??rt??' => 'GPU Gy??rt??',
			'Vide??k??rtya T??pus' => 'GPU T??pus',
			'Vide??k??rtya Jelleg' => 'GPU Jelleg',
			'Akkumul??tor Jellemz??k' => 'Akku',
			'Egy??b technikai param??retek Chipk??szlet' => 'Chipset',
			'Egy??b technikai param??retek Billenty??zet nyelve' => 'Nyelv',
			'Egy??b technikai param??retek Audio' => 'Audio',
			'Egy??b technikai param??retek Portok/egyebek' => 'Portok',
			'Egy??b technikai param??retek Port jellemz??k' => 'Portok +',
			'Egy??b technikai param??retek B??v??t??helyek' => 'ExpressCard',
			'Egy??b technikai param??retek Extr??k' => 'Tartoz??k',
			'Egy??b technikai param??retek H??l??zat' => 'LAN',
			'Egy??b technikai param??retek H??l??zati jellemz??k' => 'LAN +',
			'Oper??ci??s rendszer T??pus' => 'OS',
			'Fizikai param??terek M??ret' => 'M??ret',
			'Fizikai param??terek S??ly' => 'S??ly',
			'Garancia Id??tartam' => 'Gar.',
			'Garancia T??pus' => 'Gar. T??pus',
			'Egy??b technikai param??retek Mutat?? eszk??z' => 'Touchpad',
			'Egy??b szoftverek Gy??rt??i szoftverek' => 'Egy??b',
			'Egy??b technikai param??retek Billenty??zet jellemz??i' => 'Bill.',
			'Fizikai param??terek Sz??n' => 'Sz??n',
			'Oper??ci??s rendszer Jellemz??k' => 'OS Jell.',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'TFT T??pus' => 1,
			'TFT Jell.' => 1,
			'CPU Gy??rt??' => 1,
			'CPU Csal??d' => 1,
			'CPU T??pus' => 1,
			'CPU +' => 1,
			'RAM M??ret' => 1,
			'RAM Frek.' => 1,
			'RAM Jell.' => 1,
			'HDD M??ret' => 1,
			'HDD T??pus' => 1,
			'HDD Jell.' => 1,
			'GPU Gy??rt??' => 1,
			'GPU T??pus' => 1,
			'GPU Jelleg' => 1,
			'GPU Jell.' => 1,
			'S??ly' => 1,
			'Gar.' => 1,
			'Gar. T??pus' => 1,
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
			'Term??k neve' => '-',
			'Oper??ci??s rendszer' => 'OS',
			'Processzor' => 'CPU',
			'Mem??ria' => 'RAM',
			'SSD' => '',
			'HDD' => '',
			'Kijelz?? m??rete' => 'TFT M??ret',
			'Kijelz?? felbont??sa' => 'TFT Felb.',
			'Kijelz?? le??r??sa' => 'TFT Jell',
			'VGA t??pusa' => 'GPU',
			'Hangrendszer' => '-',
			'Kommunik??ci??' => 'LAN',
			'Akkumul??tor, ??zemid??' => 'Akku',
			'Bemeneti/Kimeneti csatlakoz??k' => 'Portok',
			'Billenty??zet' => 'Bill.',
			'S??ly ??s m??retek' => 'S??ly',
			'Szoftver' => '-',
			'Garancia' => 'Gar.',
			'Optikai egys??g' => 'ODD',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'TFT Jell' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'SSD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'Mem??ria' => 'RAM',
			'Merevlemez' => 'HDD',
			'Kijelz??' => 'TFT',
			'Videok??rtya' => 'GPU',
			'Oper??ci??s rendszer' => 'OS',
			'Optikai meghajt??' => 'ODD',
			'Sz??n' => '',
			'J??t??ll??s' => 'Gar.',
			'Bluetooth' => 'BT',
			'Express Card' => 'ExpressCard',
			'K??rtyaolvas??' => 'CR',
			'USB' => '',
			'Haszn??lt/??j' => '??llapot',
			'Billenty??zet' => 'Bill.',
			'Pozicion??l?? eszk??z' => 'Touchpad',
			'Modem' => '',
			'LAN' => '',
			'Wireless LAN' => 'WLAN',
			'Egy??b portok' => 'Portok',
			'Csal??d' => '',
			'Sorozat' => 'Modell',
			'Akkumul??tor' => 'Akku',
			'Webkamera' => 'Kamera',
			'Numerikus billenty??zet' => 'Bill. Num',
			'Dokkcsatlakoz??' => 'Dokk',
			'TV-kimenet' => 'TV ki',
			'Firewire' => 'FW',
			'Soros port' => 'RS232',
			'P??rhuzamos port' => 'LPT',
			'PCMCIA' => '',
			'HDMI' => '',
			'e-SATA' => 'eSata',
			'Ujjlenyomat-olvas??' => 'FP',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
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
			'Processzor t??pus' => 'CPU T??pus',
			'Processzor frekvencia' => 'CPU Frek.',
			'Mem??ria m??rete' => 'RAM M??ret',
			'Mem??ria t??pusa' => 'RAM T??pus',
			'Merevlemez m??rete' => 'HDD M??ret',
			'Merevlemez sebess??ge' => 'HDD Seb.',
			'Optikai meghajt??' => 'ODD',
			'Floppy' => '-',
			'Kijelz?? m??ret' => 'TFT M??ret',
			'Kijelz?? felbont??s' => 'TFT Felb.',
			'Vide??k??rtya t??pusa' => 'GPU T??pus',
			'Vide??k??rtya m??rete' => 'GPU RAM',
			'Hangk??rtya' => 'Audio',
			'H??l??zati k??rtya' => 'LAN',
			'WLAN k??rtya' => 'WLAN',
			'Modem' => '',
			'Portok' => '',
			'K??rtyaolvas??' => 'CR',
			'Akkumul??tor' => 'Akku',
			'T??meg' => 'S??ly',
			'Billenty??zet' => 'Bill.',
			'Szoftver' => 'OS',
			'Garancia' => 'Gar.',
			'Garancia-kiterjeszt??s(ek)' => '-',
			'Extr??k' => 'Egy??b',
			'??llapot' => '',
			'M??ret' => '',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'CPU T??pus' => 1,
			'CPU Frek.' => 1,
			'RAM M??ret' => 1,
			'RAM T??pus' => 1,
			'HDD M??ret' => 1,
			'HDD Seb.' => 1,
			'GPU T??pus' => 1,
			'GPU RAM' => 1,
			'S??ly' => 1,
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
			'Oper??ci??s rendszer' => 'OS',
			'Processzor' => 'CPU',
			'Mem??ria' => 'RAM',
			'Merevlemez' => 'HDD',
			'Optikai meghajt??k' => 'ODD',
			'Kijelz??' => 'TFT',
			'Grafikus k??rtya' => 'GPU',
			'Mikrofon' => '',
			'Hangszor??' => 'Audio',
			'Webkamera' => 'Kamera',
			'W-lan / Wifi' => 'WLAN',
			'VGA csatlakoz??' => 'VGA ki',
			'USB csatlakoz??k' => 'USB',
			'HDMI csatlakoz??' => 'HDMI',
			'K??rtyaolvas??' => 'CR',
			'Nyelvezet' => 'Nyelv',
			'M??ret' => '',
			'S??ly' => '',
			'Akkumul??tor' => 'Akku',
			'Csomag tartalma' => 'Tartoz??k',
			'Garancia' => 'Gar.',
			'Processzor gy??rt??' => 'CPU Gy??rt??',
			'Processzor tipus' => 'CPU T??pus',
			'Processzor sebess??g' => 'CPU Seb.',
			'Chipset' => '',
			'Mem??ria tipus' => 'RAM T??pus',
			'Megjegyz??s' => 'Egy??b',
			'Merevlemez tipus' => 'HDD T??pus',
			'Grafikus k??rtya tipusa' => 'GPU T??pus',
			'Bluetooth' => 'BT',
			'Grafikus mem??ria m??rete' => 'GPU Mem',
			'Mini D-SUB csatlakoz??' => 'Portok',
			'K??perny?? m??ret' => 'TFT M??ret',
			'Felbont??s' => 'TFT Felb.',
			'CPU' => '',
			'HDD' => '',
			'RAM' => '',
			'VGA' => 'VGA ki',
			'WLAN' => '',
			'USB' => '',
			'HDMI' => '',
			'LAN (RJ45)' => 'LAN',
			'Adapter' => 'AC',
			'Nett?? s??ly' => 'S??ly',
			'Megnevez??s' => '-'
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'CPU' => 1,
			'CPU Gy??rt??' => 1,
			'CPU T??pus' => 1,
			'CPU Seb.' => 1,
			'RAM' => 1,
			'RAM T??pus' => 1,
			'HDD' => 1,
			'HDD T??pus' => 1,
			'GPU' => 1,
			'GPU T??pus' => 1,
			'GPU Mem' => 1,
			'S??ly' => 1,
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
			'Oper??ci??s rendszer' => 'OS',
			'CPU' => '',
			'K??perny??' => 'TFT',
			'HDD' => '',
			'RAM' => '',
			'Garancia' => 'Gar.',
			'M??ret' => '',
			'Wifi???' => 'WLAN',
			'USB port' => 'USB',
			'Akku cella' => 'Akku',
			'Express card' => 'ExpressCard',
			'HDMI' => '',
			'HSUPA' => 'HSUPA',
			'Bluetooth???' => 'BT',
			'Sz??n' => '',
			'Ujjlenyomat' => 'FP',
			'IEE1394 port' => 'FW',
			'S??ly' => '',
			'Mem??ria olvas??' => 'CR',
			'Be??p??tett kamera' => 'Kamera',
			'Be??p??tett mikrofon' => 'Mikrofon',
			'SZ??N' => 'Sz??n',
			'S-video' => 'SVIDEO',
			'Arcfelismer?? szoftver' => '-',
			'RJ-45 port' => 'LAN',
			'DVD' => 'ODD',
			'Blu-ray' => 'ODD BR',
			'HSDPA' => 'HSDPA',
			'Mem??ria k??rtya' => 'CR',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'Gy??rt??: Gy??rt??' => 'Gy??rt??',
			'Gy??rt??: Csal??d' => 'Csal??d',
			'Gy??rt??: Modell' => 'Modell',
			'Processzor t??pus: Processzor t??pus' => 'CPU T??pus',
			'Processzor t??pus: Sz??moz??s' => 'CPU Sz??m',
			'Processzor t??pus: Mag neve' => 'CPU Mag',
			'Mem??ria m??ret: Mem??ria m??ret' => 'RAM M??ret',
			'Mem??ria m??ret: Frekvencia' => 'RAM Frek.',
			'Merevlemez m??ret: Merevlemez m??ret' => 'HDD M??ret',
			'Merevlemez m??ret: Tipus' => 'HDD T??pus',
			'Kijelz?? m??ret: Kijelz?? m??ret' => 'TFT M??ret',
			'Kijelz?? m??ret: Felbont??s' => 'TFT Felb.',
			'Kijelz?? m??ret: tipus' => 'TFT T??pus',
			'Vide??k??rtya gy??rt??: Vide??k??rtya gy??rt??' => 'GPU Gy??rt??',
			'Vide??k??rtya gy??rt??: jellege' => 'GPU Jell.',
			'Vide??k??rtya gy??rt??: Tipus' => 'GPU T??pus',
			'Oper??ci??s rendszer: Oper??ci??s rendszer' => 'OS',
			'Oper??ci??s rendszer: Nyelv' => 'OS Nyelv',
			'Oper??ci??s rendszer: Bit' => 'OS 32/64',
			'Oper??ci??s rendszer: Csal??d' => 'OS Csal??d',
			'Garancia id??tartam: Garancia id??tartam' => 'Gar.',
			'Garancia id??tartam: Tipus' => 'Gar. T??pus',
			'Biztos??t??s: Biztos??t??s' => 'Biztos??t??s',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): <br> Csatlakoz??k (adat??tviteli eszk??z??k)' => 'Portok',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): USB port' => 'USB',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): HDMI' => 'HDMI',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): eSata csatlakoz??' => 'eSata',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): VGA kimenet' => 'VGA ki',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): K??rtyaolvas??' => 'CR',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): LAN' => 'LAN',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): Firewire' => 'FW',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): WLAN' => 'WLAN',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): Bluetooth' => 'BT',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): Express k??rtya foglalat' => 'ExpressCard',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): Webkamera' => 'Kamera',
			'<br> Csatlakoz??k (adat??tviteli eszk??z??k): HSDPA' => 'HSDPA',
			'<br> Egy??b jellemz??k: <br> Egy??b jellemz??k' => 'Egy??b',
			'<br> Egy??b jellemz??k: Optikai meghajt?? t??pus' => 'ODD',
			'<br> Egy??b jellemz??k: Akkumul??tor cellasz??m' => 'Akku cella',
			'<br> Egy??b jellemz??k: Billenty??zet' => 'Bill.',
			'<br> Egy??b jellemz??k: Szin' => 'Sz??n',
			'<br> Egy??b jellemz??k: S??ly' => 'S??ly',
			'<br> Egy??b jellemz??k: Kensington z??r' => 'Kensington',
			'<br> Egy??b jellemz??k: <span class="first">Egy??b:</span>' => 'Egy??b +',
			'Kijelz?? m??ret: K??par??ny' => 'TFT Ar??ny',
			'<br> Egy??b jellemz??k: jellemz??' => 'Egy??b ++',
			'<br> Egy??b jellemz??k: Audio' => 'Audio',
			'<br> Egy??b jellemz??k: Chipk??szlet' => 'Chipset',
			'<br> Egy??b jellemz??k: Ujjlenyomat olvas??' => 'FP',
			'<br> Egy??b jellemz??k: Jellemz??' => 'Egy??b ++',
			'<br> Egy??b jellemz??k: Hangsz??r??' => 'Hangsz??r??',
			'<br> Egy??b jellemz??k: Egy??b inform??ci??k' => 'Egy??b +++',
			'Vide??k??rtya gy??rt??: RAM m??rete' => 'GPU Mem',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'TFT T??pus' => 1,
			'TFT Ar??ny' => 1,
			'CPU T??pus' => 1,
			'CPU Sz??m' => 1,
			'CPU Mag' => 1,
			'RAM M??ret' => 1,
			'RAM Frek.' => 1,
			'HDD M??ret' => 1,
			'HDD T??pus' => 1,
			'GPU Gy??rt??' => 1,
			'GPU Jell.' => 1,
			'GPU T??pus' => 1,
			'GPU Mem' => 1,
			'S??ly' => 1,
			'Gar.' => 1,
			'Gar. T??pus' => 1,
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
			'MEM??RIA M??RET' => 'RAM M??ret',
			'MEM??RIA T??PUS' => 'RAM T??pus',
			'K??PERNY?? ??TL??' => 'TFT M??ret',
			'FELBONT??S' => 'TFT Felb.',
			'LED KIJELZ??' => 'TFT LED',
			'INTEGR??LT VGA T??PUS' => 'GPU',
			'MEREVLEMEZ (HDD)' => 'HDD',
			'OPTIKAI MEGHAJT??' => 'ODD',
			'OPER??CI??S RENDSZER' => 'OS',
			'WLAN (WIFI)' => 'WLAN',
			'VEZET??KES H??L??ZAT' => 'LAN',
			'BLUETOOTH' => 'BT',
			'USB PORT' => 'USB',
			'eSATA' => 'eSata',
			'FIREWIRE /IEEE1394/' => 'FW',
			'KAMERA' => 'Kamera',
			'K??RTYAOLVAS??' => 'CR',
			'HDMI' => '',
			'MINI HDMI' => 'MiniHDMI',
			'MICRO HDMI' => 'MicroHDMI',
			'DISPLAYPORT' => 'DP',
			'MINI DISPLAYPORT' => 'MiniDP',
			'LOKALIZ??CI??' => 'Nyelv',
			'S??LY' => 'S??ly',
			'USB PORT (3.0)' => 'USB 3',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT Felb.' => 1,
			'TFT LED' => 1,
			'CPU' => 1,
			'RAM M??ret' => 1,
			'RAM T??pus' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
			'ODD' => 1,
			'OS' => 1,
			);

		function prepareProduct(&$product) {
			$prodname = $product['a.lightbox img']->attr('alt');
			$product->append('<div class="c_product_name">' . $prodname . '</div>');
			$product['.infobox:contains("??tv??tel")']->remove();
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
			'Felhaszn??l??si ter??let' => 'Kateg??ria',
			'Processzor' => 'CPU',
			'Chipk??szlet' => 'Chipset',
			'Kijelz??' => 'TFT',
			'3D kijelz??' => 'TFT 3D',
			'Mem??ria' => 'RAM',
			'Szabad mem??ria hely' => 'RAM Slot',
			'Merevlemez' => 'HDD',
			'Optikai meghajt??' => 'ODD',
			'Oper??ci??s rendszer' => 'OS',
			'Hangk??rtya' => 'Audio',
			'Mikrofon' => 'Mikrofon',
			'Videok??rtya' => 'GPU',
			'Webkamera' => 'Kamera',
			'Wi-Fi' => 'WLAN',
			'Bluetooth' => 'BT',
			'3G' => 'HSDPA',
			'Csatlakoz??' => 'Portok',
			'Lan' => 'LAN',
			'USB 3.0' => 'USB 3',
			'Akkumul??tor' => 'Akku',
			'M??ret' => '',
			'S??ly' => '',
			'Garancia' => 'Gar.',
			'USB' => '',
			'Extra SSD' => 'SSD',
			'Mem??riak??rtya olvas??' => 'CR',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT' => 1,
			'TFT 3D' => 1,
			'CPU' => 1,
			'RAM' => 1,
			'RAM Slot' => 1,
			'HDD' => 1,
			'GPU' => 1,
			'S??ly' => 1,
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
			'Gy??rt??' => '',
			'Processzor t??pus' => 'CPU T??pus',
			'Processzor frekvencia' => 'CPU Frek.',
			'Processzor FSB frekvencia' => 'CPU FSB',
			'Chipk??szlet' => 'Chipset',
			'Mem??ria' => 'RAM',
			'Mem??riahelyek sz??ma' => 'RAM Slot',
			'Mem??ria max. b??v??thet??s??g' => 'RAM Max',
			'Merevlemez kapacit??s' => 'HDD M??ret',
			'Merevlemez sebess??g' => 'HDD Seb.',
			'Kijelz?? m??ret' => 'TFT M??ret',
			'Kijelz?? t??pusa' => 'TFT T??pus',
			'Kijelz?? felbont??s' => 'TFT Felb.',
			'Grafikus vez??rl??' => 'GPU',
			'Grafikus vez??rl?? mem??ria' => 'GPU Mem',
			'Optikai meghajt??' => 'ODD',
			'H??l??zat' => 'LAN',
			'Csatlakoz??k' => 'Portok',
			'Audio csatlakoz??k' => 'Audio',
			'K??rtyaolvas??' => 'CR',
			'Oper??ci??s rendszer' => 'OS',
			'Oper??ci??s rendszer kompatibilit??s' => 'OS +',
			'Akkumul??tor t??pus' => 'Akku T??pus',
			'Akkumul??tor teljes??tm??ny' => 'Akku Telj.',
			'Akkumul??tor cellasz??m' => 'Akku Cella',
			'Billenty??zet kioszt??s' => 'Bill.',
			'Sz??n' => '',
			'M??ret (HxMxSz)' => 'M??ret',
			'S??ly' => '',
			'Tartoz??kok' => 'Egy??b',
			'Extr??k' => 'Egy??b +',
			'Garancia' => 'Gar.',
			);
		protected $shopParameterOrder          = array(
			'Term??kN??v' => 1,
			'??r' => 1,
			'TFT M??ret' => 1,
			'TFT T??pus' => 1,
			'TFT Felb.' => 1,
			'CPU T??pus' => 1,
			'CPU Frek.' => 1,
			'CPU FSB' => 1,
			'RAM' => 1,
			'RAM Slot' => 1,
			'RAM Max' => 1,
			'HDD M??ret' => 1,
			'HDD Seb.' => 1,
			'GPU' => 1,
			'GPU Mem' => 1,
			'S??ly' => 1,
			'Gar.' => 1,
			'Akku T??pus' => 1,
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

	// TFT, CPU, RAM, HDD, GPU, S??ly, Gar., Akku, ODD, OS

	$shop = new shopEdigital();
	$shop->getData();
