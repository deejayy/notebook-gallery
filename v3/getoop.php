#!/usr/bin/php
<?php

	require('phpQuery.php');
	define('DEBUG', 1);
	gc_enable();

	date_default_timezone_set('Europe/Budapest');

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
				helper::log('URL: ' . $url);
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
		/**
		 * called by prepareProduct(), loaded from XML
		 */
		protected $prepareFunction;

		private $shopListFiles = array();
		private $productUrls = array();
		private $products = array();
		private $productParameters = array();
		private $productValues = array();
		private static $downloader;

		function __construct($xmlPath = '') {
			date_default_timezone_set('Europe/Budapest');
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', shop name: ' . $this->shopName);
			$this->prepareFunction = create_function('&$product', '');
			$this->downloader = new downloader();
			if ($xmlPath !== '') {
				$this->loadXML($xmlPath);
			}
		}

		function setShopParameterXML($shopNode, $nodeName) {
			$nodeValue = $shopNode->getElementsByTagName($nodeName)->item(0)->nodeValue;
			if ($nodevalue !== '' && $nodeValue) {
				$this->$nodeName = $nodeValue;
			}
		}

		function loadXML($xmlPath) {
			$shopConfig = new DOMDocument();
			$shopConfig->load($xmlPath);

			foreach ($shopConfig->getElementsByTagName('shop') as $shopNode) {
				foreach (array('shopName', 'shopDomain', 'shopListUrl', 'shopProductUrlStripGet', 'shopListUrlCount', 'shopListUrlCountStart', 'shopListPostData', 'shopListPostCounterField', 'shopPqListNode', 'shopPqProductName', 'shopPqPriceField', 'shopPriceMultiplier', 'shopPqParameterNames', 'shopPqParameterValues', ) as $nodeName) {
					$this->setShopParameterXML($shopNode, $nodeName);
				}

				if ($methodSource = $shopNode->getElementsByTagName('prepareProduct')->item(0)->nodeValue) {
					$this->prepareFunction = create_function('&$product', $methodSource);
				}

				foreach ($shopNode->getElementsByTagName('shopTransliterateParameters')->item(0)->getElementsByTagName('trDefine') as $trDefine) {
					$this->shopTransliterateParameters[$trDefine->attributes->getNamedItem('title')->nodeValue] = $trDefine->nodeValue;
				}

				foreach ($shopNode->getElementsByTagName('shopParameterOrder')->item(0)->getElementsByTagName('poName') as $poName) {
					$this->shopParameterOrder[$poName->nodeValue] = 1;
				}
			}

			$shopConfig = null;
			unset($shopConfig);
			helper::log('GC: ' . gc_collect_cycles());
		}

		function getList() {
			helper::log(__FUNCTION__ . ' called on ' . get_class($this) . ', from: ' . $this->shopListUrlCountStart . ', count: ' . $this->shopListUrlCount);
			if (defined('TESTING') && TESTING) {
				$this->shopListUrlCount = $this->shopListUrlCountStart;
			}
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
			$listHtml = null;
			unset($listHtml);
			helper::log('GC: ' . gc_collect_cycles());
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
			$method = $this->prepareFunction;
			$method(&$product);
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
			$product = null;
			unset($product);
			helper::log('GC: ' . gc_collect_cycles());
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

	$d = opendir('xml');
	while ($f = readdir($d)) {
		if (preg_match('/\.xml$/', $f)) {
			$shop = new shop('xml/' . $f);
			$shop->getData();
			$shop = null;
			unset($shop);
			helper::log('GC: ' . gc_collect_cycles());
		}
	}