<?php
/**
 * Joomla! System plugin - Language Domains
 *
 * @author     Yireo (info@yireo.com)
 * @copyright  Copyright 2015 Yireo.com. All rights reserved
 * @license    GNU Public License
 * @link       https://www.yireo.com
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');

require_once JPATH_SITE . '/plugins/system/languagefilter/languagefilter.php';

/**
 * Class PlgSystemLanguageDomains
 *
 * @package     Joomla!
 * @subpackage  System
 */
class PlgSystemLanguageDomains extends PlgSystemLanguageFilter
{
	/**
	 * @var JApplicationCms
	 */
	protected $app;

	/**
	 * @var bool
	 */
	protected $bindings = false;

	/**
	 * @var string
	 */
	protected $currentLanguageTag;

	/**
	 * Constructor
	 *
	 * @param   mixed &$subject Instance of JEventDispatcher
	 * @param   mixed $config   Configuration array
	 */
	public function __construct(&$subject, $config)
	{
		$this->overrideClasses();

		$rt = parent::__construct($subject, $config);

		$this->app = JFactory::getApplication();

		// If this is the Site-application
		if ($this->app->isSite() == true)
		{
			// Detect the current language
			$currentLanguageTag = $this->detectLanguage();
			$this->setLanguage($currentLanguageTag);

			// Get the bindings
			$bindings = $this->getBindings();

			if (!empty($bindings))
			{
				// Check whether the currently defined language is in the list of domains
				if (!array_key_exists($currentLanguageTag, $bindings))
				{
					$this->setLanguage($currentLanguageTag);

					return $rt;
				}

				// Check if the current default language is correct
				foreach ($bindings as $bindingLanguageTag => $bindingDomains)
				{
					$bindingDomain = $bindingDomains['primary'];

					if (stristr(JURI::current(), $bindingDomain) == true)
					{
						// Change the current default language
						$newLanguageTag = $bindingLanguageTag;
						break;
					}
				}

				// Make sure the current language-tag is registered as current
				if (!empty($newLanguageTag) && $newLanguageTag != $currentLanguageTag)
				{
					$this->setLanguage($newLanguageTag);
				}
			}
		}

		return $rt;
	}

	/**
	 * Event onAfterInitialise
	 */
	public function onAfterInitialise()
	{
		// Remove the cookie if it exists
		$this->cleanLanguageCookie();

		// Make sure not to redirect to a URL with language prefix
		$this->params->set('remove_default_prefix', 1);

		// Enable item-associations
		$this->app->item_associations = $this->params->get('item_associations', 1);
		$this->app->menu_associations = $this->params->get('item_associations', 1);

		// If this is the Administrator-application, or if debugging is set, do nothing
		if ($this->app->isSite() == false)
		{
			return;
		}

		// Disable browser-detection
		$this->params->set('detect_browser', 0);
		$this->app->setDetectBrowser(false);

		// Detect the language
		$languageTag = JFactory::getLanguage()
			->getTag();

		// Detect the language again
		if (empty($languageTag))
		{
			$language = JFactory::getLanguage();
			$languageTag = $language->getTag();
		}

		// Get the bindings
		$bindings = $this->getBindings();

		// Preliminary checks
		if (empty($bindings) || (!empty($languageTag) && !array_key_exists($languageTag, $bindings)))
		{
			// Run the event of the parent-plugin
			parent::onAfterInitialise();

			// Re-enable item-associations
			$this->app->item_associations = $this->params->get('item_associations', 1);
			$this->app->menu_associations = $this->params->get('item_associations', 1);

			return;
		}

		// Check for an empty language
		if (empty($languageTag))
		{
			// Check if the current default language is correct
			foreach ($bindings as $bindingLanguageTag => $bindingDomains)
			{
				$bindingDomain = $bindingDomains['primary'];

				if (stristr(JURI::current(), $bindingDomain) == true)
				{
					// Change the current default language
					$newLanguageTag = $bindingLanguageTag;

					break;
				}
			}
		}

		// Override the default language if the domain was matched
		if (empty($languageTag) && !empty($newLanguageTag))
		{
			$languageTag = $newLanguageTag;
		}

		// Make sure the current language-tag is registered as current
		if (!empty($languageTag))
		{
			$this->setLanguage($languageTag);

			$component = JComponentHelper::getComponent('com_languages');
			$component->params->set('site', $languageTag);
		}

		// Run the event of the parent-plugin
		parent::onAfterInitialise();

		// Re-enable item-associations
		$this->app->item_associations = $this->params->get('item_associations', 1);
		$this->app->menu_associations = $this->params->get('item_associations', 1);

		$this->resetDefaultLanguage();
	}

	/**
	 * Event onAfterRoute
	 */
	public function onAfterRoute()
	{
		// Run the event of the parent-plugin
		if (method_exists(get_parent_class(), 'onAfterRoute'))
		{
			parent::onAfterRoute();
		}

		// Remove the cookie if it exists
		$this->cleanLanguageCookie();

		// If this is the Administrator-application, or if debugging is set, do nothing
		if ($this->app->isSite() == false)
		{
			return;
		}

		// Detect the current language again, but now after routing
		$languageTag = $this->detectLanguage();

		// If this language is not included in this plugins configuration, set it as current
		if (!$this->isLanguageBound($languageTag))
		{
			$this->setLanguage($languageTag, true);
		}
		// If this language is included in this plugins configuration, override the language again
		else
		{
			$this->setLanguage($this->currentLanguageTag, true);
		}

		$this->debug('Current language tag: ' . $languageTag);

		if (empty($languageTag))
		{
			$this->redirectLanguageToDomain($languageTag);
		}

		$this->redirectDomainToPrimaryDomain($languageTag);

		$this->resetPathForHome($languageTag);
	}

	/**
	 * Event onAfterDispatch - left empty to catch event for parent plugin
	 */
	public function onAfterDispatch()
	{
		parent::onAfterDispatch();
	}

	/**
	 * Event onAfterRender
	 */
	public function onAfterRender()
	{
		// If this is the Administrator-application, or if debugging is set, do nothing
		if ($this->app->isAdmin() || JDEBUG)
		{
			return;
		}

		// Fetch the document buffer
		$buffer = JResponse::getBody();

		// Get the bindings
		$bindings = $this->getBindings();

		if (empty($bindings))
		{
			return;
		}

		// Loop through the languages and check for any URL
		$languages = JLanguageHelper::getLanguages('sef');

		foreach ($languages as $languageSef => $language)
		{
			$languageCode = $language->lang_code;

			if (!array_key_exists($languageCode, $bindings))
			{
				continue;
			}

			if (empty($bindings[$languageCode]))
			{
				continue;
			}

			if (empty($languageSef))
			{
				continue;
			}

			$primaryDomain = $bindings[$languageCode]['primary'];
			$primaryUrl = $this->getUrlFromDomain($primaryDomain);

			$secondaryDomains = $bindings[$languageCode]['domains'];

			// Replace shortened URLs
			$this->rewriteShortUrls($buffer, $languageSef, $primaryUrl, $secondaryDomains);

			// Replace shortened URLs that contain /index.php/
			$this->rewriteShortUrlsWithIndex($buffer, $languageSef, $primaryUrl, $secondaryDomains);

			// Replace full URLs
			$this->rewriteFullUrls($buffer, $languageSef, $primaryUrl, $primaryDomain, $secondaryDomains);
		}

		JResponse::setBody($buffer);
	}

	/**
	 * Override of the build rule of the parent plugin
	 *
	 * @param   JRouter &$router JRouter object.
	 * @param   JUri    &$uri    JUri object.
	 *
	 * @return  void
	 */
	public function buildRule(&$router, &$uri)
	{
		// Make sure to append the language prefix to all URLs, so we can properly parse the HTML using onAfterRender()
		$this->params->set('remove_default_prefix', 0);

		parent::buildRule($router, $uri);
	}

	/**
	 * Replace all short URLs with a language X with a domain Y
	 *
	 * @param $buffer           string
	 * @param $languageSef      string
	 * @param $primaryUrl       string
	 * @param $secondaryDomains array
	 *
	 * @return void
	 */
	protected function rewriteShortUrls(&$buffer, $languageSef, $primaryUrl, $secondaryDomains)
	{
		if (preg_match_all('/([\'\"]{1})\/(' . $languageSef . ')\/([^\'\"]+)([\'"]?)/', $buffer, $matches))
		{
			foreach ($matches[0] as $index => $match)
			{
				$this->debug('Match shortened URL: ' . $match);

				if ($this->allowUrlChange($match) == false)
				{
					continue;
				}

				if ($this->doesSefMatchCurrentLanguage($languageSef))
				{
					$buffer = str_replace($match, $matches[1][$index] . $matches[3][$index] . $matches[4][$index], $buffer);
				}
				else
				{
					$buffer = str_replace($match, $matches[1][$index] . $primaryUrl . $matches[3][$index] . $matches[4][$index], $buffer);
				}
			}
		}
	}

	/**
	 * Replace all short URLs containing /index.php/ with a language X with a domain Y
	 *
	 * @param $buffer
	 * @param $languageSef
	 * @param $primaryUrl
	 */
	protected function rewriteShortUrlsWithIndex(&$buffer, $languageSef, $primaryUrl, $secondaryDomains)
	{
		$config = JFactory::getConfig();

		if ($config->get('sef_rewrite', 0) == 0)
		{
			if (preg_match_all('/([\'\"]{1})\/index.php\/(' . $languageSef . ')\/([^\'\"]+)([\'"]?)/', $buffer, $matches))
			{
				foreach ($matches[0] as $index => $match)
				{
					$this->debug('Match shortened URL with /index.php/: ' . $match);

					if ($this->allowUrlChange($match) == true)
					{
						$buffer = str_replace($match, $matches[1][$index] . $primaryUrl . $matches[3][$index] . $matches[4][$index], $buffer);
					}
				}
			}
		}
	}

	/**
	 * Replace all full URLs with a language X with a domain Y
	 *
	 * @param $buffer           string
	 * @param $languageSef      string
	 * @param $primaryUrl       string
	 * @param $primaryDomain    string
	 * @param $secondaryDomains array
	 *
	 * @return bool
	 */
	protected function rewriteFullUrls(&$buffer, $languageSef, $primaryUrl, $primaryDomain, $secondaryDomains)
	{
		$bindings = $this->getBindings();
		$allDomains = $this->getAllDomains();

		if (empty($bindings))
		{
			return false;
		}

		// Scan for full URLs
		if (preg_match_all('/(http|https)\:\/\/([a-zA-Z0-9\-\/\.]{5,40})\/' . $languageSef . '\/([^\'\"]+)([\'"]?)/', $buffer, $matches))
		{
			foreach ($matches[0] as $index => $match)
			{
				$this->debug('Match full URL: ' . $match);

				if ($this->allowUrlChange($match) == true)
				{
					$match = preg_replace('/(\'|\")/', '', $match);
					$workMatch = str_replace('index.php/', '', $match);
					$matchedDomain = $this->getDomainFromUrl($workMatch);

					// Skip domains that are not within this configuration
					if (!in_array($matchedDomain, $allDomains))
					{
						continue;
					}

					// Replace the domain name
					if (!in_array($matchedDomain, $secondaryDomains) && !in_array('www.' . $matchedDomain, $secondaryDomains))
					{
						$buffer = str_replace($match, $primaryUrl . $matches[3][$index] . $matches[4][$index], $buffer);
						continue;
					}

					// Replace the language suffix in secondary domains because it is not needed
					if (in_array($matchedDomain, $secondaryDomains) || in_array('www.' . $matchedDomain, $secondaryDomains))
					{
						$url = $primaryUrl;

						if ($this->params->get('enforce_domains', 0) == 0)
						{
							$url = str_replace($primaryDomain, $matchedDomain, $url);
						}

						$buffer = str_replace($match, $url . $matches[3][$index] . $matches[4][$index], $buffer);
						continue;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Method to get all the domains configured in this plugin
	 *
	 * @return array
	 */
	protected function getAllDomains()
	{
		$bindings = $this->getBindings();
		$allDomains = array();

		if (empty($bindings))
		{
			return $allDomains;
		}

		foreach ($bindings as $binding)
		{
			$allDomains[] = $binding['primary'];

			if (is_array($binding['domains']))
			{
				$allDomains = array_merge($allDomains, $binding['domains']);
			}
		}

		return $allDomains;
	}

	/**
	 * Method to get the bindings for languages
	 *
	 * @return null
	 */
	protected function getBindings()
	{
		if (is_array($this->bindings))
		{
			return $this->bindings;
		}

		$bindings = trim($this->params->get('bindings'));

		if (empty($bindings))
		{
			$this->bindings = array();

			return $this->bindings;
		}

		$bindingsArray = explode("\n", $bindings);
		$bindings = array();

		foreach ($bindingsArray as $index => $binding)
		{
			$binding = trim($binding);

			if (empty($binding))
			{
				continue;
			}

			$binding = explode('=', $binding);

			if (!isset($binding[0]) || !isset($binding[1]))
			{
				continue;
			}

			$languageCode = trim($binding[0]);
			$languageCode = str_replace('_', '-', $languageCode);

			if (preg_match('/([^a-zA-Z\-]+)/', $languageCode))
			{
				continue;
			}

			$domainString = trim($binding[1]);
			$domainParts = explode('|', $domainString);
			$domain = array_shift($domainParts);

			if (!is_array($domainParts))
			{
				$domainParts = array();
			}

			$bindings[$languageCode] = array(
				'primary' => $domain,
				'domains' => $domainParts);
		}

		$this->bindings = $bindings;

		return $this->bindings;
	}

	/**
	 * Helper-method to get the language bound to specific domain
	 *
	 * @param   string $domain Domain to determine language from
	 *
	 * @return string
	 */
	protected function getLanguageFromDomain($domain = null)
	{
		if (empty($domain))
		{
			$uri = JURI::getInstance();
			$domain = $uri->toString(array('host'));
		}

		$bindings = $this->getBindings();

		foreach ($bindings as $languageTag => $binding)
		{
			if ($binding['primary'] == $domain || 'www.' . $binding['primary'] == $domain)
			{
				return $languageTag;
			}

			if (in_array($domain, $binding['domains']))
			{
				return $languageTag;
			}
		}

		return null;
	}

	/**
	 * Helper-method to get a proper URL from the domain @access public @param string @return string
	 *
	 * @param   string $domain Domain to obtain the URL from
	 *
	 * @return string
	 */
	protected function getUrlFromDomain($domain)
	{
		// Add URL-elements to the domain
		if (preg_match('/^(http|https):\/\//', $domain) == false)
		{
			$domain = 'http://' . $domain;
		}

		if (preg_match('/\/$/', $domain) == false)
		{
			$domain = $domain . '/';
		}

		$config = JFactory::getConfig();

		if ($config->get('sef_rewrite', 0) == 0 && preg_match('/index\.php/', $domain) == false)
		{
			$domain = $domain . 'index.php/';
		}

		return $domain;
	}

	/**
	 * Helper-method to get a proper URL from the domain @access public @param string @return string
	 *
	 * @param   string $url URL to obtain the domain from
	 *
	 * @return string
	 */
	protected function getDomainFromUrl($url)
	{
		// Add URL-elements to the domain
		if (preg_match('/^(http|https):\/\/([a-zA-Z0-9\.\-\_]+)/', $url, $match))
		{
			$domain = $match[2];

			return $domain;
		}

		return false;
	}

	/**
	 * Redirect to a certain domain based on a language tag
	 *
	 * @param $languageTag
	 *
	 * @return bool
	 */
	protected function redirectLanguageToDomain($languageTag)
	{
		// Check whether to allow redirects or to leave things as they are
		$allowRedirect = $this->allowRedirect();

		if ($allowRedirect == false)
		{
			return false;
		}

		// Get the language domain
		$domain = $this->getDomainByLanguageTag($languageTag);

		if (!empty($domain))
		{
			if (stristr(JURI::current(), $domain) == false)
			{
				// Add URL-elements to the domain
				$domain = $this->getUrlFromDomain($domain);

				// Replace the current domain with the new domain
				$currentUrl = JURI::current();
				$newUrl = str_replace(JURI::base(), $domain, $currentUrl);

				// Set the cookie
				$conf = JFactory::getConfig();
				$cookie_domain = $conf->get('config.cookie_domain', '');
				$cookie_path = $conf->get('config.cookie_path', '/');
				setcookie(JApplicationHelper::getHash('language'), null, time() - 365 * 86400, $cookie_path, $cookie_domain);

				// Redirect
				$this->app->redirect($newUrl);
				$this->app->close();
			}
		}

		return true;
	}

	protected function redirectDomainToPrimaryDomain($languageTag)
	{
		// Check whether to allow redirects or to leave things as they are
		$allowRedirect = $this->allowRedirect();

		if ($allowRedirect == false)
		{
			return false;
		}

		if ($this->params->get('enforce_domains', 0) == 0)
		{
			return false;
		}

		$bindings = $this->getBindings();
		$primaryDomain = $this->getDomainByLanguageTag($languageTag);
		$currentDomain = JURI::getInstance()
			->getHost();

		if (empty($bindings))
		{
			return false;
		}

		foreach ($bindings as $binding)
		{
			if (in_array($currentDomain, $binding['domains']))
			{
				$primaryDomain = $binding['primary'];
			}
		}

		if (stristr(JURI::current(), '/' . $primaryDomain) == false)
		{
			// Replace the current domain with the new domain
			$currentUrl = JURI::current();
			$newUrl = str_replace($currentDomain, $primaryDomain, $currentUrl);

			// Redirect
			$this->app->redirect($newUrl);
			$this->app->close();
		}

		return true;
	}

	/**
	 * Return the domain by language tag
	 *
	 * @param $languageTag
	 *
	 * @return mixed
	 */
	protected function getDomainByLanguageTag($languageTag)
	{
		$bindings = $this->getBindings();

		if (empty($bindings))
		{
			return false;
		}

		if (!array_key_exists($languageTag, $bindings))
		{
			return false;
		}

		return $bindings[$languageTag]['primary'];
	}

	/**
	 * Reset the URI path to include the language SEF part specifically for the home Menu-Item
	 *
	 * @param $languageTag
	 */
	public function resetPathForHome($languageTag)
	{
		$menu = $this->app->getMenu();
		$active = $menu->getActive();

		if (!empty($active) && $active->home == 1)
		{
			$languageSef = $this->getLanguageSefByTag($languageTag);
			$uri = JUri::getInstance();
			$uri->setPath('/' . $languageSef . '/');
		}
	}

	/**
	 * Find the SEF part of a certain language tag
	 *
	 * @param $languageTag
	 *
	 * @return int|null|string
	 */
	public function getLanguageSefByTag($languageTag)
	{
		$languages = JLanguageHelper::getLanguages('sef');
		$currentLanguageSef = null;

		foreach ($languages as $languageSef => $language)
		{
			if ($language->lang_code == $languageTag)
			{
				$currentLanguageSef = $languageSef;
				break;
			}
		}

		return $currentLanguageSef;
	}

	/**
	 * Return the domain by language tag
	 *
	 * @param $languageTag
	 *
	 * @return mixed
	 */
	protected function getDomainsByLanguageTag($languageTag)
	{
		$bindings = $this->getBindings();

		if (empty($bindings))
		{
			return false;
		}

		if (array_key_exists($languageTag, $bindings))
		{
			return $bindings[$languageTag]['domains'];
		}
	}

	/**
	 * Wipe language cookie
	 *
	 * @return bool
	 */
	protected function cleanLanguageCookie()
	{
		if (method_exists('JApplicationHelper', 'getHash'))
		{
			$languageHash = JApplicationHelper::getHash('language');
		}
		else
		{
			$languageHash = JApplication::getHash('language');
		}

		if (!isset($_COOKIE[$languageHash]))
		{
			return false;
		}

		$conf = JFactory::getConfig();
		$cookie_domain = $conf->get('config.cookie_domain', '');
		$cookie_path = $conf->get('config.cookie_path', '/');

		setcookie($languageHash, '', time() - 3600, $cookie_path, $cookie_domain);
		$this->app->input->cookie->set($languageHash, '');

		return true;
	}

	/**
	 * Detect the current language
	 *
	 * @return string
	 */
	protected function detectLanguage()
	{
		$currentLanguageTag = $this->app->input->get('language');

		if (empty($currentLanguageTag))
		{
			$currentLanguageTag = $this->app->input->get('lang');
		}

		if (empty($currentLanguageTag))
		{
			$currentLanguageTag = $this->getLanguageFromDomain();
		}

		if (empty($currentLanguageTag))
		{
			$currentLanguageTag = JFactory::getLanguage()
				->getTag();
		}

		return $currentLanguageTag;
	}

	/**
	 * Change the current language
	 *
	 * @param string $languageTag Tag of a language
	 * @param bool   $fullInit    Fully initialize the language or not
	 *
	 * @return null
	 */
	protected function setLanguage($languageTag, $fullInit = false)
	{
		$this->currentLanguageTag = $languageTag;
		$this->current_lang = $languageTag;

		$prop = new ReflectionProperty($this, 'default_lang');
		if ($prop->isStatic())
		{
			self::$default_lang = $languageTag;
		}
		else
		{
			$this->default_lang = $languageTag;
		}

		// Set the input variable
		$this->app->input->set('language', $languageTag);
		$this->app->input->set('lang', $languageTag);

		// Rerun the constructor ugly style
		JFactory::getLanguage()
			->__construct($languageTag);

		// Reload languages
		$language = JLanguage::getInstance($languageTag, false);

		if ($fullInit == true)
		{
			$language->load('tpl_' . $this->app->getTemplate(), JPATH_SITE, $languageTag, true);
		}

		$language->load('joomla', JPATH_SITE, $languageTag, true);
		$language->load('lib_joomla', JPATH_SITE, $languageTag, true);

		// Reinject the language back into the application
		try
		{
			$this->app->set('language', $languageTag);
		}
		catch (Exception $e)
		{
			return;
		}

		if (method_exists($this->app, 'loadLanguage'))
		{
			$this->app->loadLanguage($language);
		}

		// Reset the JFactory
		try
		{
			JFactory::$language = $language;
		}
		catch (Exception $e)
		{
			return;
		}
	}

	/**
	 * Allow a redirect
	 *
	 * @return bool
	 */
	private function allowRedirect()
	{
		$input = $this->app->input;

		if ($input->getMethod() == "POST" || count($input->post) > 0 || count($input->files) > 0)
		{
			return false;
		}

		if ($input->getCmd('tmpl') == 'component')
		{
			return false;
		}

		if (in_array($input->getCmd('format'), array('json', 'feed', 'api', 'opchtml')))
		{
			return false;
		}

		if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
		{
			return false;
		}

		return true;
	}

	/**
	 * Allow a specific URL to be changed by this plugin
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	private function allowUrlChange($url)
	{
		// Exclude specific component-calls
		if (preg_match('/format=(raw|json|api)/', $url))
		{
			return false;
		}

		// Exclude specific JavaScript
		if (preg_match('/\.js$/', $url))
		{
			return false;
		}

		// Do not rewrite non-SEF URLs
		if (stristr($url, 'index.php?option='))
		{
			return false;
		}

		// Exclude specific components
		$exclude_components = $this->getArrayFromParam('exclude_components');

		if (!empty($exclude_components))
		{
			foreach ($exclude_components as $exclude_component)
			{
				if (stristr($url, 'components/' . $exclude_component))
				{
					return false;
				}

				if (stristr($url, 'option=' . $exclude_component . '&'))
				{
					return false;
				}
			}
		}

		// Exclude specific URLs
		$exclude_urls = $this->getArrayFromParam('exclude_urls');
		$exclude_urls[] = '/media/jui/js/';
		$exclude_urls[] = '/assets/js/';

		if (!empty($exclude_urls))
		{
			foreach ($exclude_urls as $exclude_url)
			{
				if (stristr($url, $exclude_url))
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check whether a certain SEF string matches the current language
	 *
	 * @param string $sef
	 *
	 * @return bool
	 */
	private function doesSefMatchCurrentLanguage($sef)
	{
		$languages = JLanguageHelper::getLanguages('sef');
		if (!isset($languages[$sef]))
		{
			return false;
		}

		$language = $languages[$sef];
		$currentLanguage = JFactory::getLanguage();

		if ($currentLanguage->getTag() == $language->lang_code)
		{
			return true;
		}

		return false;
	}

	/**
	 * Get an array from a parameter
	 *
	 * @param string $param
	 *
	 * @return array
	 */
	private function getArrayFromParam($param)
	{
		$data = $this->params->get($param);
		$data = trim($data);

		if (empty($data))
		{
			return array();
		}

		$data = explode(',', $data);

		$newData = array();

		foreach ($data as $value)
		{
			$value = trim($value);

			if (!empty($value))
			{
				$newData[] = $value;
			}
		}

		return $newData;
	}

	/**
	 * Debug a certain message
	 *
	 * @param $message
	 *
	 * @return bool
	 */
	private function debug($message)
	{
		if ($this->allowRedirect() == false)
		{
			return false;
		}

		$debug = false;
		$input = $this->app->input;

		if ($input->getInt('debug') == 1)
		{
			$debug = true;
		}

		if ($this->params->get('debug') == 1)
		{
			$debug = true;
		}

		if ($debug)
		{
			echo '<script>console.log("LANGUAGE DOMAINS: ' . addslashes($message) . '");</script>';
		}

		return true;
	}

	/**
	 * Reset the current language (with $%& VirtueMart support)
	 */
	private function resetDefaultLanguage()
	{
		//JFactory::getLanguage()->setDefault('en_GB');

		if (!class_exists('VmConfig'))
		{
			$vmConfigFile = JPATH_ROOT . '/administrator/components/com_virtuemart/helpers/config.php';

			if (file_exists($vmConfigFile))
			{
				defined('DS') or define('DS', DIRECTORY_SEPARATOR);

				include_once $vmConfigFile;

				VmConfig::loadConfig();
			}
		}

		if (class_exists('VmConfig'))
		{
			VmConfig::$vmlang = false;
			VmConfig::setdbLanguageTag();
		}
	}

	/**
	 * Quick check to see if a specific language tag is included in the bindings
	 *
	 * @param $languageTag
	 *
	 * @return bool
	 */
	private function isLanguageBound($languageTag)
	{
		$bindings = $this->getBindings();

		if (isset($bindings[$languageTag]))
		{
			return true;
		}

		return false;
	}

	/**
	 * Method to override certain Joomla classes
	 */
	private function overrideClasses()
	{
		JLoader::import('joomla.version');
		$version = new JVersion;
		$majorVersion = $version->getShortVersion();

		if (version_compare($majorVersion, '3.2', 'ge'))
		{
			require_once JPATH_SITE . '/plugins/system/languagedomains/rewrite-32/associations.php';
			require_once JPATH_SITE . '/plugins/system/languagedomains/rewrite-32/multilang.php';
		}
	}
}
