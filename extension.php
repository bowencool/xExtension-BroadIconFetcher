<?php

declare(strict_types=1);

/**
 * Fetches feed icons from HTML, Web App Manifest and common metadata fields.
 */
final class IconFetcherExtension extends Minz_Extension
{
	private const MAX_HTML_BYTES = 2_000_000;
	private const MAX_MANIFEST_BYTES = 512_000;

	private bool $autoFetchNewFeeds = true;

	#[\Override]
	public function init(): void {
		parent::init();

		$this->registerHook('custom_favicon_btn_url', [$this, 'iconButtonUrl']);
		$this->registerHook('custom_favicon_hash', [$this, 'iconHashParams']);
		$this->registerHook('feed_before_insert', [$this, 'feedBeforeInsert']);

		if (Minz_Request::controllerName() === 'extension') {
			Minz_View::appendScript($this->getFileUrl('icon-fetcher.js'));
		}

		$this->registerTranslates();
	}

	/**
	 * Add a per-feed action to FreshRSS's custom favicon dialog.
	 */
	public function iconButtonUrl(FreshRSS_Feed $feed): ?string {
		// Never replace a favicon explicitly uploaded by the user.
		if ($feed->customFavicon() && $feed->customFaviconExt() === null) {
			return null;
		}

		// The bulk action on the configuration page is still available for icons
		// already managed by this extension.
		if ($feed->customFaviconExt() === $this->getName()) {
			return null;
		}

		return _url('extension', 'configure', 'e', urlencode($this->getName()));
	}

	/**
	 * Make the custom favicon path stable for this extension and feed.
	 */
	public function iconHashParams(FreshRSS_Feed $feed): ?string {
		if ($feed->customFaviconExt() !== $this->getName()) {
			return null;
		}

		return 'icon-fetcher|' . $feed->website() . '|' . $feed->url() . '|' . $feed->proxyParam();
	}

	/**
	 * Fetch an icon when a new feed is added, unless the user disabled it.
	 */
	public function feedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		$this->loadConfigValues();

		if ($this->autoFetchNewFeeds) {
			$this->setIconForFeed($feed);
		}

		return $feed;
	}

	/**
	 * Handles both FreshRSS's per-feed favicon action and this extension's
	 * configuration-page AJAX actions.
	 */
	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$extAction = Minz_Request::paramStringNull('extAction');
			if ($extAction !== null) {
				$this->handleFaviconAction($extAction);
				return;
			}

			switch (Minz_Request::paramString('icon_action')) {
				case 'ajax_list_feeds':
					$this->ajaxListFeeds();
					return;
				case 'ajax_fetch_icon':
					$this->ajaxFetchIcon();
					return;
				case 'reset_icons':
					$this->resetAllIcons();
					break;
			}

			if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasUserConf()) {
				FreshRSS_Context::userConf()->_attribute(
					'icon_fetcher_auto_new',
					Minz_Request::paramBoolean('icon_fetcher_auto_new')
				);
				FreshRSS_Context::userConf()->save();
			}
		}

		$this->loadConfigValues();
	}

	/**
	 * @throws Minz_PDOConnectionException
	 * @throws Minz_ConfigurationNamespaceException
	 */
	private function ajaxListFeeds(): void {
		$feedDao = FreshRSS_Factory::createFeedDao();
		$feeds = [];

		foreach ($feedDao->listFeedsIds() as $feedId) {
			$feed = $feedDao->searchById($feedId);
			if ($feed === null) {
				continue;
			}
			$feeds[] = [
				'id' => $feed->id(),
				'title' => $feed->name(true),
			];
		}

		header('Content-Type: application/json; charset=UTF-8');
		exit(json_encode($feeds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @throws Minz_PDOConnectionException
	 * @throws Minz_ConfigurationNamespaceException
	 */
	private function ajaxFetchIcon(): void {
		$feedDao = FreshRSS_Factory::createFeedDao();
		$feed = $feedDao->searchById(Minz_Request::paramInt('id'));
		if ($feed === null) {
			Minz_Error::error(404);
			return;
		}

		$updated = $this->setIconForFeed(
			$feed,
			setValues: true,
			force: Minz_Request::paramBoolean('force'),
		);

		header('Content-Type: application/json; charset=UTF-8');
		exit(json_encode(['ok' => $updated]));
	}

	/**
	 * Handle FreshRSS's custom favicon button protocol.
	 */
	private function handleFaviconAction(string $action): void {
		$feedDao = FreshRSS_Factory::createFeedDao();
		$feed = $feedDao->searchById(Minz_Request::paramInt('id'));
		if ($feed === null) {
			Minz_Error::error(404);
			return;
		}

		if (!in_array($action, ['query_icon_info', 'update_icon'], true)) {
			Minz_Error::error(400);
			return;
		}

		$this->setIconForFeed(
			$feed,
			setValues: $action === 'update_icon',
			force: $action === 'update_icon',
		);

		if ($action === 'query_icon_info') {
			header('Content-Type: application/json; charset=UTF-8');
			exit(json_encode([
				'extName' => $this->getName(),
				'iconUrl' => $feed->favicon(),
			], JSON_UNESCAPED_SLASHES));
		}

		exit('OK');
	}

	/**
	 * Remove only icons owned by this extension.
	 *
	 * @throws Minz_PDOConnectionException
	 * @throws Minz_ConfigurationNamespaceException
	 */
	private function resetAllIcons(): void {
		$feedDao = FreshRSS_Factory::createFeedDao();

		foreach ($feedDao->listFeedsIds() as $feedId) {
			$feed = $feedDao->searchById($feedId);
			if ($feed === null || $feed->customFaviconExt() !== $this->getName()) {
				continue;
			}

			$values = [];
			try {
				$feed->resetCustomFavicon(values: $values);
			} catch (Throwable $exception) {
				$this->warning('Failed to reset icon for feed “' . $feed->name(true) . '”: ' . $exception->getMessage());
			}
		}
	}

	/**
	 * Download and persist one feed icon.
	 *
	 * The first attributes-only call gives FreshRSS the final favicon path and
	 * also lets us detect an icon explicitly owned by the user. The old
	 * attributes are restored before any network request fails.
	 */
	private function setIconForFeed(FreshRSS_Feed $feed, bool $setValues = false, bool $force = false): bool {
		$values = $setValues ? [] : null;
		$oldAttributes = $feed->attributes();

		try {
			$path = $feed->setCustomFavicon(
				extName: $this->getName(),
				disallowDelete: false,
				values: $values,
			);
			if ($path === null) {
				$feed->_attributes($oldAttributes);
				return false;
			}

			if (!$force && file_exists($path)) {
				return true;
			}
		} catch (Throwable $exception) {
			$feed->_attributes($oldAttributes);
			$this->warning('Unable to prepare icon path for feed “' . $feed->name(true) . '”: ' . $exception->getMessage());
			return false;
		}

		$feed->_attributes($oldAttributes);
		$icon = $this->discoverIcon($feed, $force);
		if ($icon === null) {
			$this->warning('No usable icon found for feed “' . $feed->name(true) . '”.');
			return false;
		}

		try {
			$feed->setCustomFavicon(
				$icon['contents'],
				extName: $this->getName(),
				disallowDelete: false,
				values: $values,
				overrideCustomIcon: true,
			);
			$this->debug('Using ' . $icon['source'] . ' for feed “' . $feed->name(true) . '”.');
			return true;
		} catch (Throwable $exception) {
			$feed->_attributes($oldAttributes);
			$this->warning('Unable to save icon for feed “' . $feed->name(true) . '”: ' . $exception->getMessage());
			return false;
		}
	}

	/**
	 * @return array{contents:string,source:string}|null
	 */
	private function discoverIcon(FreshRSS_Feed $feed, bool $force = false): ?array {
		$pageUrl = $this->feedPageUrl($feed);
		if ($pageUrl === null) {
			return null;
		}

		$candidates = [];
		$this->collectChannelImageCandidates($feed, $candidates, $force);
		$response = $this->request($pageUrl, $feed, 'html', '', $force);
		$html = is_string($response['body'] ?? null) ? $response['body'] : '';
		$effectiveUrl = is_string($response['effective_url'] ?? null) ? $response['effective_url'] : $pageUrl;

		if ($html !== '' && strlen($html) <= self::MAX_HTML_BYTES) {
			$dom = new DOMDocument();
			if (@$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
				$xpath = new DOMXPath($dom);
				$baseUrl = $this->documentBaseUrl($xpath, $effectiveUrl) ?? $effectiveUrl;
				$this->collectLinkCandidates($xpath, $baseUrl, $feed, $candidates, $force);
				$this->collectMetaCandidates($xpath, $baseUrl, $candidates);
				$this->collectJsonLdCandidates($xpath, $baseUrl, $candidates);
			}
		}

		$rootIcon = $this->rootFaviconUrl($effectiveUrl);
		if ($rootIcon !== null) {
			$this->addCandidate($candidates, $rootIcon, 1, 0, 'root favicon');
		}

		$candidates = array_values($candidates);
		usort($candidates, static function (array $left, array $right): int {
			return [$right['score'], $right['size']] <=> [$left['score'], $left['size']];
		});

		foreach ($candidates as $candidate) {
			$iconResponse = $this->request($candidate['url'], $feed, 'ico', $effectiveUrl, $force);
			$contents = $iconResponse['body'] ?? null;
			if (!is_string($contents) || !$this->isImage($contents)) {
				continue;
			}

			return [
				'contents' => $contents,
				'source' => $candidate['source'],
			];
		}

		return null;
	}

	/**
	 * RSSHub emits the channel image as RSS <channel><image><url>...</url>,
	 * which corresponds to channel.image.url in its JSON representation.
	 * Read the feed itself before looking at the website HTML so RSSHub's
	 * channel avatar wins over a generic favicon on the source website.
	 *
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function collectChannelImageCandidates(FreshRSS_Feed $feed, array &$candidates, bool $force = false): void {
		$feedUrl = $this->checkedUrl(trim($feed->url()));
		if ($feedUrl === null) {
			return;
		}

		$response = $this->request($feedUrl, $feed, 'xml', '', $force);
		$body = $response['body'] ?? null;
		if (!is_string($body) || $body === '' || strlen($body) > self::MAX_HTML_BYTES) {
			return;
		}

		$effectiveUrl = is_string($response['effective_url'] ?? null) ? $response['effective_url'] : $feedUrl;
		$this->addCandidate(
			$candidates,
			$this->extractChannelImageUrl($body, $effectiveUrl),
			100,
			0,
			'invalid RSS channel image candidate',
			'RSS/Atom channel.image.url',
		);
	}

	private function extractChannelImageUrl(string $body, string $baseUrl): ?string {
		$json = json_decode(ltrim($body), true);
		if (is_array($json)) {
			$jsonPaths = [
				['channel', 'image', 'url'],
				['channel', 'image', 'href'],
				['channel', 'logo', 'url'],
				['channel', 'logo'],
				['image', 'url'],
				['image'],
				['icon'],
				['logo'],
			];

			foreach ($jsonPaths as $path) {
				$value = $json;
				foreach ($path as $key) {
					if (!is_array($value) || !array_key_exists($key, $value)) {
						$value = null;
						break;
					}
					$value = $value[$key];
				}
				if (is_string($value) && ($url = $this->resolveUrl($baseUrl, trim($value))) !== null) {
					return $url;
				}
			}
		}

		$dom = new DOMDocument();
		if (!@$dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
			return null;
		}

		$xpath = new DOMXPath($dom);
		$nodes = $xpath->query(
			'//*[local-name()="channel"]/*[local-name()="image"]/*[local-name()="url"]'
			. ' | //*[local-name()="feed"]/*[local-name()="icon"]'
			. ' | //*[local-name()="feed"]/*[local-name()="logo"]'
		);
		if (!($nodes instanceof DOMNodeList)) {
			return null;
		}

		foreach ($nodes as $node) {
			$url = $this->resolveUrl($baseUrl, trim($node->textContent));
			if ($url !== null) {
				return $url;
			}
		}

		return null;
	}

	private function feedPageUrl(FreshRSS_Feed $feed): ?string {
		$website = trim($feed->website());
		$url = $website !== '' ? $website : trim($feed->url());
		return $this->checkedUrl($url);
	}

	/**
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function collectLinkCandidates(DOMXPath $xpath, string $baseUrl, FreshRSS_Feed $feed, array &$candidates, bool $force = false): void {
		$links = $xpath->query('//link[@href]');
		if (!($links instanceof DOMNodeList)) {
			return;
		}

		foreach ($links as $link) {
			if (!($link instanceof DOMElement)) {
				continue;
			}

			$relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
			$href = trim($link->getAttribute('href'));
			if ($href === '' || in_array('manifest', $relations, true)) {
				if ($href !== '' && in_array('manifest', $relations, true)) {
					$this->collectManifestCandidates($baseUrl, $href, $feed, $candidates, $force);
				}
				continue;
			}

			$score = match (true) {
				in_array('apple-touch-icon-precomposed', $relations, true) => 86,
				in_array('apple-touch-icon', $relations, true) => 84,
				in_array('icon', $relations, true) => 82,
				in_array('shortcut', $relations, true) && in_array('icon', $relations, true) => 80,
				in_array('mask-icon', $relations, true) => 70,
				in_array('fluid-icon', $relations, true) => 68,
				in_array('image_src', $relations, true) => 64,
				default => 0,
			};
			if ($score === 0) {
				continue;
			}

			$this->addCandidate(
				$candidates,
				$this->resolveUrl($baseUrl, $href),
				$score,
				$this->largestSize($link->getAttribute('sizes')),
				'invalid link candidate',
				$link->getAttribute('rel') ?: 'link icon',
			);
		}
	}

	/**
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function collectManifestCandidates(string $baseUrl, string $manifestHref, FreshRSS_Feed $feed, array &$candidates, bool $force = false): void {
		$manifestUrl = $this->resolveUrl($baseUrl, $manifestHref);
		if ($manifestUrl === null) {
			return;
		}

		$response = $this->request($manifestUrl, $feed, 'json', $baseUrl, $force);
		$body = $response['body'] ?? null;
		if (!is_string($body) || $body === '' || strlen($body) > self::MAX_MANIFEST_BYTES) {
			return;
		}

		$manifest = json_decode($body, true);
		if (!is_array($manifest) || !is_array($manifest['icons'] ?? null)) {
			return;
		}

		$manifestBase = is_string($response['effective_url'] ?? null) ? $response['effective_url'] : $manifestUrl;
		foreach ($manifest['icons'] as $icon) {
			if (!is_array($icon) || !is_string($icon['src'] ?? null)) {
				continue;
			}

			$this->addCandidate(
				$candidates,
				$this->resolveUrl($manifestBase, $icon['src']),
				78,
				$this->largestSize(is_string($icon['sizes'] ?? null) ? $icon['sizes'] : ''),
				'invalid manifest candidate',
				'web app manifest',
			);
		}
	}

	/**
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function collectMetaCandidates(DOMXPath $xpath, string $baseUrl, array &$candidates): void {
		$metaElements = $xpath->query('//meta[@content]');
		if (!($metaElements instanceof DOMNodeList)) {
			return;
		}

		foreach ($metaElements as $meta) {
			if (!($meta instanceof DOMElement)) {
				continue;
			}

			$name = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
			if (!in_array($name, ['og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'image'], true)) {
				continue;
			}

			$this->addCandidate(
				$candidates,
				$this->resolveUrl($baseUrl, trim($meta->getAttribute('content'))),
				42,
				0,
				'invalid metadata candidate',
				$name,
			);
		}
	}

	/**
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function collectJsonLdCandidates(DOMXPath $xpath, string $baseUrl, array &$candidates): void {
		$scripts = $xpath->query('//script[@type="application/ld+json"]');
		if (!($scripts instanceof DOMNodeList)) {
			return;
		}

		foreach ($scripts as $script) {
			if (!($script instanceof DOMElement)) {
				continue;
			}
			$decoded = json_decode(trim($script->textContent), true);
			if (is_array($decoded)) {
				$this->walkJsonLd($decoded, $baseUrl, $candidates);
			}
		}
	}

	/**
	 * @param mixed $value
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function walkJsonLd(mixed $value, string $baseUrl, array &$candidates, ?string $key = null): void {
		$imageKeys = ['image', 'logo', 'icon', 'thumbnailurl', 'contenturl'];
		$keyName = strtolower($key ?? '');

		if (is_string($value) && in_array($keyName, $imageKeys, true)) {
			$this->addCandidate(
				$candidates,
				$this->resolveUrl($baseUrl, trim($value)),
				30,
				0,
				'invalid JSON-LD candidate',
				'JSON-LD ' . $keyName,
			);
			return;
		}

		if (!is_array($value)) {
			return;
		}

		if (in_array($keyName, ['image', 'logo', 'icon'], true)) {
			foreach (['url', 'contentUrl', '@id'] as $urlKey) {
				if (is_string($value[$urlKey] ?? null)) {
					$this->addCandidate(
						$candidates,
						$this->resolveUrl($baseUrl, trim($value[$urlKey])),
						30,
						0,
						'invalid JSON-LD candidate',
						'JSON-LD ' . $keyName,
					);
				}
			}
		}

		foreach ($value as $childKey => $childValue) {
			$this->walkJsonLd($childValue, $baseUrl, $candidates, is_string($childKey) ? $childKey : null);
		}
	}

	private function documentBaseUrl(DOMXPath $xpath, string $fallback): ?string {
		$baseElements = $xpath->query('//base[@href]');
		if (!($baseElements instanceof DOMNodeList) || $baseElements->length === 0) {
			return $fallback;
		}
		$baseElement = $baseElements->item(0);
		if (!($baseElement instanceof DOMElement)) {
			return $fallback;
		}
		return $this->resolveUrl($fallback, trim($baseElement->getAttribute('href'))) ?? $fallback;
	}

	/**
	 * @param array<string,array{url:string,score:int,size:int,source:string}> $candidates
	 */
	private function addCandidate(
		array &$candidates,
		?string $url,
		int $score,
		int $size,
		string $invalidSource,
		string $source = ''
	): void {
		if ($url === null || $url === '') {
			return;
		}
		$candidates[$url] = [
			'url' => $url,
			'score' => max($score, $candidates[$url]['score'] ?? 0),
			'size' => max($size, $candidates[$url]['size'] ?? 0),
			'source' => ($candidates[$url]['source'] ?? '') !== '' ? $candidates[$url]['source'] : ($source !== '' ? $source : $invalidSource),
		];
	}

	private function resolveUrl(string $baseUrl, string $rawUrl): ?string {
		$rawUrl = trim($rawUrl);
		if ($rawUrl === '' || str_starts_with(strtolower($rawUrl), 'data:') || str_starts_with(strtolower($rawUrl), 'javascript:')) {
			return null;
		}

		if (str_starts_with($rawUrl, '//')) {
			$scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
			$rawUrl = $scheme . ':' . $rawUrl;
		}

		try {
			$iri = \SimplePie\IRI::absolutize($baseUrl, $rawUrl);
			if ($iri === false) {
				return null;
			}
			$absoluteUrl = $iri->get_iri();
			return is_string($absoluteUrl) ? $this->checkedUrl($absoluteUrl) : null;
		} catch (Throwable) {
			return null;
		}
	}

	private function checkedUrl(string $url): ?string {
		if (!preg_match('#^https?://#i', trim($url))) {
			return null;
		}

		try {
			$checked = FreshRSS_http_Util::checkUrl(trim($url), fixScheme: false);
			return is_string($checked) && $checked !== '' ? $checked : null;
		} catch (Throwable) {
			return null;
		}
	}

	private function rootFaviconUrl(string $url): ?string {
		$parts = parse_url($url);
		if (!is_array($parts) || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null)) {
			return null;
		}

		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return $this->checkedUrl($parts['scheme'] . '://' . $parts['host'] . $port . '/favicon.ico');
	}

	private function largestSize(string $sizes): int {
		$largest = 0;
		foreach (preg_split('/\s+/', strtolower(trim($sizes))) ?: [] as $size) {
			if (preg_match('/^(\d+)x(\d+)$/', $size, $matches) === 1) {
				$largest = max($largest, (int) $matches[1], (int) $matches[2]);
			}
		}
		return $largest;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function request(string $url, FreshRSS_Feed $feed, string $type, string $referer = '', bool $noCache = false): array {
		$options = $feed->attributeArray('curl_params');
		$options = is_array($options) ? FreshRSS_http_Util::sanitizeCurlParams($options) : [];
		if ($referer !== '') {
			$options[CURLOPT_REFERER] = $referer;
		}

		$cachePath = $noCache ? null : CACHE_PATH . '/' . sha1($url) . '.' . $type;
		try {
			return FreshRSS_http_Util::httpGet($url, cachePath: $cachePath, type: $type, curl_options: $options);
		} catch (Throwable $exception) {
			$this->debug('Request failed for ' . $url . ': ' . $exception->getMessage());
			return ['body' => '', 'fail' => true, 'effective_url' => $url];
		}
	}

	private function isImage(string $contents): bool {
		require_once LIB_PATH . '/favicons.php';
		return isImgMime($contents);
	}

	private function loadConfigValues(): void {
		if (!class_exists('FreshRSS_Context', false) || !FreshRSS_Context::hasUserConf()) {
			return;
		}

		$value = FreshRSS_Context::userConf()->attributeBool('icon_fetcher_auto_new');
		if ($value !== null) {
			$this->autoFetchNewFeeds = $value;
		}
	}

	public function isAutoFetchNewFeeds(): bool {
		$this->loadConfigValues();
		return $this->autoFetchNewFeeds;
	}

	private function warning(string $message): void {
		try {
			Minz_Log::warning('[' . $this->getName() . '] ' . $message);
		} catch (Throwable) {
			// Logging must never prevent a feed from being imported.
		}
	}

	private function debug(string $message): void {
		try {
			Minz_Log::debug('[' . $this->getName() . '] ' . $message);
		} catch (Throwable) {
			// Logging must never prevent a feed from being imported.
		}
	}
}
