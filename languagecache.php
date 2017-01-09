<?php
defined('_JEXEC') or die;

class PlgSystemLanguageCache extends JPlugin
{
	protected $app;

	private static $cachingEnabled = false;

	private static $usingCacheLanguage = false;

	public function __construct(&$subject, $config)
	{
		// Instantiate our extended JLanguage object
		$lang  = JFactory::getConfig()->get('language');
		$debug = JFactory::getConfig()->get('debug_lang');

		parent::__construct($subject, $config);

		self::$cachingEnabled = (bool) $this->params->get('cache_language', '0');

		if (static::cachingEnabled() && file_exists(JPATH_CACHE . '/cachedLanguage.' . $lang . '.php'))
		{
			JLoader::register('CachedLanguage', JPATH_CACHE . '/cachedLanguage.' . $lang . '.php');
			JFactory::$language = new CachedLanguage($lang, $debug);

			self::$usingCacheLanguage = true;
		}
	}

	public function onAfterRender()
	{
		$lang = JFactory::getLanguage();

		// To get the language code, we'll need Reflection
		$refl = new ReflectionClass($lang);

		$property = $refl->getProperty('lang');
		$property->setAccessible(true);

		$langCode = $property->getValue($lang);

		if (static::cachingEnabled() && !file_exists(JPATH_CACHE . '/cachedLanguage.' . $langCode . '.php'))
		{
			static::generateCache($lang);
		}
	}

	public static function cachingEnabled()
	{
		return self::$cachingEnabled;
	}

	public static function generateCache(JLanguage $lang)
	{
		$file = <<<PHP
<?php
defined('_JEXEC') or die;

class CachedLanguage extends JLanguage
{
	private static \$hasChanged = false;

	protected \$strings = array(

PHP;

		// To get the strings, we'll need Reflection
		$refl = new ReflectionClass($lang);

		$property = $refl->getProperty('strings');
		$property->setAccessible(true);

		$strings = $property->getValue($lang);

		foreach ($strings as $key => $value)
		{
			$file .= "\t\t'$key' => '" . addcslashes($value, '\\\'') . "',\n";
		}

		$file .= <<<PHP
	);

	protected \$paths = array(

PHP;
		foreach ($lang->getPaths() as $extension => $paths)
		{
			$file .= "\t\t'$extension' => array(\n";

			foreach ($paths as $path => $loaded)
			{
				$file .= "\t\t\t'$path' => " . ($loaded ? 'true' : 'false') . ",\n";
			}

			$file .= "\t\t),\n";
		}

		$file .= <<<PHP
	);

	/**
	 * Constructor activating the default information of the language.
	 *
	 * @param   string   \$lang   The language
	 * @param   boolean  \$debug  Indicates if language debugging is enabled.
	 *
	 * @note    This constructor is overloaded to prevent resetting the strings property to an empty array and implicitly trying to load the base strings
	 */
	public function __construct(\$lang = null, \$debug = false)
	{
		if (\$lang == null)
		{
			\$lang = \$this->default;
		}

		\$this->lang = \$lang;
		\$this->metadata = \$this->getMetadata(\$this->lang);
		\$this->setDebug(\$debug);

		// Look for a language specific localise class
		\$class = str_replace('-', '_', \$lang . 'Localise');
		\$paths = array();

		if (defined('JPATH_SITE'))
		{
			// Note: Manual indexing to enforce load order.
			\$paths[0] = JPATH_SITE . "/language/overrides/\$lang.localise.php";
			\$paths[2] = JPATH_SITE . "/language/\$lang/\$lang.localise.php";
		}

		if (defined('JPATH_ADMINISTRATOR'))
		{
			// Note: Manual indexing to enforce load order.
			\$paths[1] = JPATH_ADMINISTRATOR . "/language/overrides/\$lang.localise.php";
			\$paths[3] = JPATH_ADMINISTRATOR . "/language/\$lang/\$lang.localise.php";
		}

		ksort(\$paths);
		\$path = reset(\$paths);

		while (!class_exists(\$class) && \$path)
		{
			if (file_exists(\$path))
			{
				require_once \$path;
			}

			\$path = next(\$paths);
		}

		if (class_exists(\$class))
		{
			/* Class exists. Try to find
			 * -a transliterate method,
			 * -a getPluralSuffixes method,
			 * -a getIgnoredSearchWords method
			 * -a getLowerLimitSearchWord method
			 * -a getUpperLimitSearchWord method
			 * -a getSearchDisplayCharactersNumber method
			 */
			if (method_exists(\$class, 'transliterate'))
			{
				\$this->transliterator = array(\$class, 'transliterate');
			}

			if (method_exists(\$class, 'getPluralSuffixes'))
			{
				\$this->pluralSuffixesCallback = array(\$class, 'getPluralSuffixes');
			}

			if (method_exists(\$class, 'getIgnoredSearchWords'))
			{
				\$this->ignoredSearchWordsCallback = array(\$class, 'getIgnoredSearchWords');
			}

			if (method_exists(\$class, 'getLowerLimitSearchWord'))
			{
				\$this->lowerLimitSearchWordCallback = array(\$class, 'getLowerLimitSearchWord');
			}

			if (method_exists(\$class, 'getUpperLimitSearchWord'))
			{
				\$this->upperLimitSearchWordCallback = array(\$class, 'getUpperLimitSearchWord');
			}

			if (method_exists(\$class, 'getSearchDisplayedCharactersNumber'))
			{
				\$this->searchDisplayedCharactersNumberCallback = array(\$class, 'getSearchDisplayedCharactersNumber');
			}
		}
	}

	public function __destruct()
	{
		if (self::\$hasChanged)
		{
			PlgSystemLanguageCache::generateCache(\$this);
		}
	}

	public function load(\$extension = 'joomla', \$basePath = JPATH_BASE, \$lang = null, \$reload = false, \$default = true)
	{
		static \$lastExtension = null;

		\$result = true;

		/*
		 * Check if the extension is already loaded or we're still trying to load an extension
		 * If it isn't already loaded then we need to load it and regenerate the cache
		 */
		if (!\$this->getPaths(\$extension) || \$lastExtension === \$extension)
		{
			\$lastExtension = \$extension;

			self::\$hasChanged = true;
	
			\$result = parent::load(\$extension, \$basePath, \$lang, \$reload, \$default);
		}
	
		return \$result;
	}
}
PHP;

		jimport('joomla.filesystem.file');

		JFile::write(JPATH_CACHE . '/cachedLanguage.' . JFactory::getConfig()->get('language') . '.php', $file);
	}
}
