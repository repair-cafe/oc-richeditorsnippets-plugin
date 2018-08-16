<?php namespace Inetis\RicheditorSnippets\Classes;

use Cache;
use Config;
use Event;
use Cms\Classes\Controller as CmsController;
use Cms\Classes\Theme;
use Cms\Classes\ComponentManager;
use RainLab\Pages\Classes\SnippetManager;

class SnippetLoader
{
	protected static $pageSnippetsCache = null;

	/**
	 * Add a component registered as a snippet to the active controller.
	 *
	 * @param array $snippetInfo	The info of the snippet to register
	 * @return string				The generated unique alias for this snippet
	 */
	public static function registerComponentSnippet($snippetInfo)
	{
		$theme = Theme::getActiveTheme();
		$controller = CmsController::getController();

		// Get an unique alias for this snippet based on its name and parameters
		$snippetAlias = uniqid($snippetInfo['code'] . '-' . md5(serialize($snippetInfo['properties'])) . '-');

		$component = $controller->addComponent($snippetInfo['component'], $snippetAlias, $snippetInfo['properties'], true);
		self::cacheSnippet($snippetAlias, $snippetInfo);

		// Trigger the onRun handler to mimic CMS lifecycle
		$component->onRun();

		return $snippetAlias;
	}

	/**
	 * Add a partial registered as a snippet to the active controller.
	 *
	 * @param array $snippetInfo	The info of the snippet to register
	 * @return string				The generated unique alias for this snippet
	 */
	public static function registerPartialSnippet($snippetInfo)
	{
		$theme = Theme::getActiveTheme();
		$partialSnippetMap = SnippetManager::instance()->getPartialSnippetMap($theme);
		$snippetCode = $snippetInfo['code'];

		if (!array_key_exists($snippetCode, $partialSnippetMap)) {
			throw new ApplicationException(sprintf('Partial for the snippet %s is not found', $snippetCode));
		}

		return $partialSnippetMap[$snippetCode];
	}

	/**
	 * Save to the cache the component snippets loaded for this page.
	 * Should be called once after all snippets are loaded to avoid multiple serializations.
	 */
	public static function saveCachedSnippets()
	{
		self::fetchCachedSnippets();

		Cache::put(
			self::getMapCacheKey(),
			serialize(self::$pageSnippetsCache),
			Config::get('cms.parsedPageCacheTTL', 10)
		);
	}

	/**
	 * Register back to the current controller all component snippets previously saved.
	 * This make AJAX handlers of these components available.
	 *
	 * @param CmsController $cmsController
	 */
	public static function restoreComponentSnippetsFromCache($cmsController)
	{
		$componentSnippets = self::fetchCachedSnippets();

		$componentManager = ComponentManager::instance();
        foreach ($componentSnippets as $componentInfo) {
            // Register components for snippet-based components
            // if they're not defined yet. This is required because
            // not all snippet components are registered as components,
            // but it's safe to register them in render-time.

            if (!$componentManager->hasComponent($componentInfo['component'])) {
                $componentManager->registerComponent($componentInfo['component'], $componentInfo['code']);
            }

            $cmsController->addComponent(
                $componentInfo['component'],
                $componentInfo['code'],
                $componentInfo['properties']
            );
        }
	}

	/**
	 * Store a component snippet to the cache.
	 * The cache is not actually saved; saveCachedSnippets() must be called to persist the cache.
	 *
	 * @param string $alias			The unique alias of the snippet
	 * @param array $snippetInfo	The info of the snippet
	 */
	protected static function cacheSnippet($alias, $snippetInfo)
	{
		self::fetchCachedSnippets();
		self::$pageSnippetsCache[$alias] = $snippetInfo;
	}

	/**
	 * Load cached component snippets from the cache.
	 * If it has already be loaded once, it won't do anything.
	 */
	protected static function fetchCachedSnippets()
	{
		if (self::$pageSnippetsCache !== null) {
			return self::$pageSnippetsCache;
		}

        $cached = Cache::get(self::getMapCacheKey(), false);

		if ($cached !== false) {
			$cached = @unserialize($cached);
		}

		if (!is_array($cached)) {
			$cached = [];
		}

		return $cached;
	}

	/**
	 * Get a cache key for the current page.
	 *
	 * @return string
	 */
	protected static function getMapCacheKey()
    {
		$theme = Theme::getActiveTheme();

        return crc32($theme->getPath()).'dynamic-snippet-map';
    }
}
