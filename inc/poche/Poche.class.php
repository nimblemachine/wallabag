<?php
/**
 * wallabag, self hostable application allowing you to not miss any content anymore
 *
 * @category   wallabag
 * @author     Nicolas Lœuillet <nicolas@loeuillet.org>
 * @copyright  2013
 * @license    http://www.wtfpl.net/ see COPYING file
 */

class Poche
{
    public static $canRenderTemplates = true;
    public static $configFileAvailable = true;

    public $user;
    public $store;
    public $tpl;
    public $messages;
    public $pagination;

    private $currentTheme = '';
    private $currentLanguage = '';
    private $notInstalledMessage = array();

    private $language_names = array(
      'cs_CZ.utf8' => 'čeština',
      'de_DE.utf8' => 'German',
      'en_EN.utf8' => 'English',
      'es_ES.utf8' => 'Español',
      'fa_IR.utf8' => 'فارسی',
      'fr_FR.utf8' => 'Français',
      'it_IT.utf8' => 'Italiano',
      'pl_PL.utf8' => 'Polski',
      'pt_BR.utf8' => 'Português (Brasil)',
      'ru_RU.utf8' => 'Pусский',
      'sl_SI.utf8' => 'Slovenščina',
      'uk_UA.utf8' => 'Українська',
    );
    public function __construct()
    {
        if ($this->configFileIsAvailable()) {
            $this->init();
        }

        if ($this->themeIsInstalled()) {
            $this->initTpl();
        }

        if ($this->systemIsInstalled()) {
            $this->store = new Database();
            $this->messages = new Messages();
            # installation
            if (! $this->store->isInstalled()) {
                $this->install();
            }
            $this->store->checkTags();
        }
    }

    private function init()
    {
        Tools::initPhp();

        if (isset($_SESSION['poche_user']) && $_SESSION['poche_user'] != array()) {
            $this->user = $_SESSION['poche_user'];
        } else {
            # fake user, just for install & login screens
            $this->user = new User();
            $this->user->setConfig($this->getDefaultConfig());
        }

        # l10n
        $language = $this->user->getConfigValue('language');
        putenv('LC_ALL=' . $language);
        setlocale(LC_ALL, $language);
        bindtextdomain($language, LOCALE);
        textdomain($language);

        # Pagination
        $this->pagination = new Paginator($this->user->getConfigValue('pager'), 'p');

        # Set up theme
        $themeDirectory = $this->user->getConfigValue('theme');

        if ($themeDirectory === false) {
            $themeDirectory = DEFAULT_THEME;
        }

        $this->currentTheme = $themeDirectory;

        # Set up language
        $languageDirectory = $this->user->getConfigValue('language');

        if ($languageDirectory === false) {
            $languageDirectory = DEFAULT_THEME;
        }

        $this->currentLanguage = $languageDirectory;
    }

    public function configFileIsAvailable() {
        if (! self::$configFileAvailable) {
            $this->notInstalledMessage[] = 'You have to rename inc/poche/config.inc.php.new to inc/poche/config.inc.php.';

            return false;
        }

        return true;
    }

    public function themeIsInstalled() {
        $passTheme = TRUE;
        # Twig is an absolute requirement for Poche to function. Abort immediately if the Composer installer hasn't been run yet
        if (! self::$canRenderTemplates) {
            $this->notInstalledMessage[] = 'Twig does not seem to be installed. Please initialize the Composer installation to automatically fetch dependencies. You can also download <a href="http://wllbg.org/vendor">vendor.zip</a> and extract it in your wallabag folder.';
            $passTheme = FALSE;
        }

        if (! is_writable(CACHE)) {
            $this->notInstalledMessage[] = 'You don\'t have write access on cache directory.';

            self::$canRenderTemplates = false;

            $passTheme = FALSE;
        }

        # Check if the selected theme and its requirements are present
        $theme = $this->getTheme();

        if ($theme != '' && ! is_dir(THEME . '/' . $theme)) {
            $this->notInstalledMessage[] = 'The currently selected theme (' . $theme . ') does not seem to be properly installed (Missing directory: ' . THEME . '/' . $theme . ')';

            self::$canRenderTemplates = false;

            $passTheme = FALSE;
        }

        $themeInfo = $this->getThemeInfo($theme);
        if (isset($themeInfo['requirements']) && is_array($themeInfo['requirements'])) {
            foreach ($themeInfo['requirements'] as $requiredTheme) {
                if (! is_dir(THEME . '/' . $requiredTheme)) {
                    $this->notInstalledMessage[] = 'The required "' . $requiredTheme . '" theme is missing for the current theme (' . $theme . ')';

                    self::$canRenderTemplates = false;

                    $passTheme = FALSE;
                }
            }
        }

        if (!$passTheme) {
            return FALSE;
        }


        return true;
    }

    /**
     * all checks before installation.
     * @todo move HTML to template
     * @return boolean
     */
    public function systemIsInstalled()
    {
        $msg = TRUE;

        $configSalt = defined('SALT') ? constant('SALT') : '';

        if (empty($configSalt)) {
            $this->notInstalledMessage[] = 'You have not yet filled in the SALT value in the config.inc.php file.';
            $msg = FALSE;
        }
        if (STORAGE == 'sqlite' && ! file_exists(STORAGE_SQLITE)) {
            Tools::logm('sqlite file doesn\'t exist');
            $this->notInstalledMessage[] = 'sqlite file doesn\'t exist, you can find it in install folder. Copy it in /db folder.';
            $msg = FALSE;
        }
        if (is_dir(ROOT . '/install') && ! DEBUG_POCHE) {
            $this->notInstalledMessage[] = 'you have to delete the /install folder before using poche.';
            $msg = FALSE;
        }
        if (STORAGE == 'sqlite' && ! is_writable(STORAGE_SQLITE)) {
            Tools::logm('you don\'t have write access on sqlite file');
            $this->notInstalledMessage[] = 'You don\'t have write access on sqlite file.';
            $msg = FALSE;
        }

        if (! $msg) {
            return false;
        }

        return true;
    }

    public function getNotInstalledMessage() {
        return $this->notInstalledMessage;
    }

    private function initTpl()
    {
        $loaderChain = new Twig_Loader_Chain();
        $theme = $this->getTheme();

        # add the current theme as first to the loader chain so Twig will look there first for overridden template files
        try {
            $loaderChain->addLoader(new Twig_Loader_Filesystem(THEME . '/' . $theme));
        } catch (Twig_Error_Loader $e) {
            # @todo isInstalled() should catch this, inject Twig later
            die('The currently selected theme (' . $theme . ') does not seem to be properly installed (' . THEME . '/' . $theme .' is missing)');
        }

        # add all required themes to the loader chain
        $themeInfo = $this->getThemeInfo($theme);
        if (isset($themeInfo['requirements']) && is_array($themeInfo['requirements'])) {
            foreach ($themeInfo['requirements'] as $requiredTheme) {
                try {
                    $loaderChain->addLoader(new Twig_Loader_Filesystem(THEME . '/' . $requiredTheme));
                } catch (Twig_Error_Loader $e) {
                    # @todo isInstalled() should catch this, inject Twig later
                    die('The required "' . $requiredTheme . '" theme is missing for the current theme (' . $theme . ')');
                }
            }
        }

        if (DEBUG_POCHE) {
            $twigParams = array();
        } else {
            $twigParams = array('cache' => CACHE);
        }

        $this->tpl = new Twig_Environment($loaderChain, $twigParams);
        $this->tpl->addExtension(new Twig_Extensions_Extension_I18n());

        # filter to display domain name of an url
        $filter = new Twig_SimpleFilter('getDomain', 'Tools::getDomain');
        $this->tpl->addFilter($filter);

        # filter for reading time
        $filter = new Twig_SimpleFilter('getReadingTime', 'Tools::getReadingTime');
        $this->tpl->addFilter($filter);
    }

    private function install()
    {
        Tools::logm('poche still not installed');
        echo $this->tpl->render('install.twig', array(
            'token' => Session::getToken(),
            'theme' => $this->getTheme(),
            'poche_url' => Tools::getPocheUrl()
        ));
        if (isset($_GET['install'])) {
            if (($_POST['password'] == $_POST['password_repeat'])
                && $_POST['password'] != "" && $_POST['login'] != "") {
                # let's rock, install poche baby !
                if ($this->store->install($_POST['login'], Tools::encodeString($_POST['password'] . $_POST['login'])))
                {
                    Session::logout();
                    Tools::logm('poche is now installed');
                    Tools::redirect();
                }
            }
            else {
                Tools::logm('error during installation');
                Tools::redirect();
            }
        }
        exit();
    }

    public function getTheme() {
        return $this->currentTheme;
    }

    /**
     * Provides theme information by parsing theme.ini file if present in the theme's root directory.
     * In all cases, the following data will be returned:
     * - name: theme's name, or key if the theme is unnamed,
     * - current: boolean informing if the theme is the current user theme.
     *
     * @param string $theme Theme key (directory name)
     * @return array|boolean Theme information, or false if the theme doesn't exist.
     */
    public function getThemeInfo($theme) {
        if (!is_dir(THEME . '/' . $theme)) {
            return false;
        }

        $themeIniFile = THEME . '/' . $theme . '/theme.ini';
        $themeInfo = array();

        if (is_file($themeIniFile) && is_readable($themeIniFile)) {
            $themeInfo = parse_ini_file($themeIniFile);
        }

        if ($themeInfo === false) {
            $themeInfo = array();
        }
        if (!isset($themeInfo['name'])) {
            $themeInfo['name'] = $theme;
        }
        $themeInfo['current'] = ($theme === $this->getTheme());

        return $themeInfo;
    }

    public function getInstalledThemes() {
        $handle = opendir(THEME);
        $themes = array();

        while (($theme = readdir($handle)) !== false) {
            # Themes are stored in a directory, so all directory names are themes
            # @todo move theme installation data to database
            if (!is_dir(THEME . '/' . $theme) || in_array($theme, array('.', '..'))) {
                continue;
            }

            $themes[$theme] = $this->getThemeInfo($theme);
        }

        ksort($themes);

        return $themes;
    }

    public function getLanguage() {
        return $this->currentLanguage;
    }

    public function getInstalledLanguages() {
        $handle = opendir(LOCALE);
        $languages = array();

        while (($language = readdir($handle)) !== false) {
            # Languages are stored in a directory, so all directory names are languages
            # @todo move language installation data to database
            if (! is_dir(LOCALE . '/' . $language) || in_array($language, array('..', '.', 'tools'))) {
                continue;
            }

            $current = false;

            if ($language === $this->getLanguage()) {
                $current = true;
            }

            $languages[] = array('name' => (isset($this->language_names[$language]) ? $this->language_names[$language] : $language), 'value' => $language, 'current' => $current);
        }

        return $languages;
    }

    public function getDefaultConfig()
    {
        return array(
            'pager' => PAGINATION,
            'language' => LANG,
            'theme' => DEFAULT_THEME
        );
    }

    /**
     * Call action (mark as fav, archive, delete, etc.)
     */
    public function action($action, Url $url, $id = 0, $import = FALSE, $autoclose = FALSE, $tags = null)
    {
        switch ($action)
        {
            case 'add':
                $content = Tools::getPageContent($url);
                $title = ($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled');
                $body = $content['rss']['channel']['item']['description'];

                // clean content from prevent xss attack
                $config = HTMLPurifier_Config::createDefault();
                $config->set('Cache.SerializerPath', CACHE);
                $purifier = new HTMLPurifier($config);
                $title = $purifier->purify($title);
                $body = $purifier->purify($body);

                //search for possible duplicate
                $duplicate = NULL;
                $duplicate = $this->store->retrieveOneByURL($url->getUrl(), $this->user->getId());

                $last_id = $this->store->add($url->getUrl(), $title, $body, $this->user->getId());
                if ( $last_id ) {
                    Tools::logm('add link ' . $url->getUrl());
                    if (DOWNLOAD_PICTURES) {
                        $content = filtre_picture($body, $url->getUrl(), $last_id);
                        Tools::logm('updating content article');
                        $this->store->updateContent($last_id, $content, $this->user->getId());
                    }

                    if ($duplicate != NULL) {
                        // duplicate exists, so, older entry needs to be deleted (as new entry should go to the top of list), BUT favorite mark and tags should be preserved
                        Tools::logm('link ' . $url->getUrl() . ' is a duplicate');
                        // 1) - preserve tags and favorite, then drop old entry
                        $this->store->reassignTags($duplicate['id'], $last_id);
                        if ($duplicate['is_fav']) {
                          $this->store->favoriteById($last_id, $this->user->getId());
                        }
                        if ($this->store->deleteById($duplicate['id'], $this->user->getId())) {
                          Tools::logm('previous link ' . $url->getUrl() .' entry deleted');
                        }
                    }

                    $this->messages->add('s', _('the link has been added successfully'));
                }
                else {
                    $this->messages->add('e', _('error during insertion : the link wasn\'t added'));
                    Tools::logm('error during insertion : the link wasn\'t added ' . $url->getUrl());
                }

                if ($autoclose == TRUE) {
                  Tools::redirect('?view=home');
                } else {
                  Tools::redirect('?view=home&closewin=true');
                }
                break;
            case 'delete':
                $msg = 'delete link #' . $id;
                if ($this->store->deleteById($id, $this->user->getId())) {
                    if (DOWNLOAD_PICTURES) {
                        remove_directory(ABS_PATH . $id);
                    }
                    $this->messages->add('s', _('the link has been deleted successfully'));
                }
                else {
                    $this->messages->add('e', _('the link wasn\'t deleted'));
                    $msg = 'error : can\'t delete link #' . $id;
                }
                Tools::logm($msg);
                Tools::redirect('?');
                break;
            case 'toggle_fav' :
                $this->store->favoriteById($id, $this->user->getId());
                Tools::logm('mark as favorite link #' . $id);
                Tools::redirect();
                break;
            case 'toggle_archive' :
                $this->store->archiveById($id, $this->user->getId());
                Tools::logm('archive link #' . $id);
                Tools::redirect();
                break;
            case 'archive_all' :
                $this->store->archiveAll($this->user->getId());
                Tools::logm('archive all links');
                Tools::redirect();
                break;
            case 'add_tag' :
                $tags = explode(',', $_POST['value']);
                $entry_id = $_POST['entry_id'];
                $entry = $this->store->retrieveOneById($entry_id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                //get all already set tags to preven duplicates
                $already_set_tags = array();
                $entry_tags = $this->store->retrieveTagsByEntry($entry_id);
                foreach ($entry_tags as $tag) {
                  $already_set_tags[] = $tag['value'];
                }
                foreach($tags as $key => $tag_value) {
                    $value = trim($tag_value);
                    if ($value && !in_array($value, $already_set_tags)) {
                      $tag = $this->store->retrieveTagByValue($value);

                      if (is_null($tag)) {
                          # we create the tag
                          $tag = $this->store->createTag($value);
                          $sequence = '';
                          if (STORAGE == 'postgres') {
                              $sequence = 'tags_id_seq';
                          }
                          $tag_id = $this->store->getLastId($sequence);
                      }
                      else {
                          $tag_id = $tag['id'];
                      }

                      # we assign the tag to the article
                      $this->store->setTagToEntry($tag_id, $entry_id);
                    }
                }
                Tools::redirect();
                break;
            case 'remove_tag' :
                $tag_id = $_GET['tag_id'];
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $this->store->removeTagForEntry($id, $tag_id);
                Tools::redirect();
                break;
            default:
                break;
        }
    }

    function displayView($view, $id = 0)
    {
        $tpl_vars = array();

        switch ($view)
        {
            case 'config':
                $dev_infos = $this->getPocheVersion('dev');
                $dev = trim($dev_infos[0]);
                $check_time_dev = date('d-M-Y H:i', $dev_infos[1]);
                $prod_infos = $this->getPocheVersion('prod');
                $prod = trim($prod_infos[0]);
                $check_time_prod = date('d-M-Y H:i', $prod_infos[1]);
                $compare_dev = version_compare(POCHE, $dev);
                $compare_prod = version_compare(POCHE, $prod);
                $themes = $this->getInstalledThemes();
                $languages = $this->getInstalledLanguages();
                $token = $this->user->getConfigValue('token');
                $http_auth = (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['REMOTE_USER'])) ? true : false;
                $tpl_vars = array(
                    'themes' => $themes,
                    'languages' => $languages,
                    'dev' => $dev,
                    'prod' => $prod,
                    'check_time_dev' => $check_time_dev,
                    'check_time_prod' => $check_time_prod,
                    'compare_dev' => $compare_dev,
                    'compare_prod' => $compare_prod,
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'http_auth' => $http_auth,
                );
                Tools::logm('config view');
                break;
            case 'edit-tags':
                # tags
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $tags = $this->store->retrieveTagsByEntry($id);
                $tpl_vars = array(
                    'entry_id' => $id,
                    'tags' => $tags,
                    'entry' => $entry,
                );
                break;
            case 'tags':
                $token = $this->user->getConfigValue('token');
                //if term is set - search tags for this term
                $term = Tools::checkVar('term');
                $tags = $this->store->retrieveAllTags($this->user->getId(), $term);
                if (Tools::isAjaxRequest()) {
                  $result = array();
                  foreach ($tags as $tag) {
                    $result[] = $tag['value'];
                  }
                  echo json_encode($result);
                  exit;
                }
                $tpl_vars = array(
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'tags' => $tags,
                );
                break;
            case 'search':
                if (isset($_GET['search'])) {
                   $search = filter_var($_GET['search'], FILTER_SANITIZE_STRING);
                   $tpl_vars['entries'] = $this->store->search($search, $this->user->getId());
                   $count = count($tpl_vars['entries']);
                   $this->pagination->set_total($count);
                   $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                            $this->pagination->page_links('?view=' . $view . '?search=' . $search . '&sort=' . $_SESSION['sort'] . '&' ));
                   $tpl_vars['page_links'] = $page_links;
                   $tpl_vars['nb_results'] = $count;
                   $tpl_vars['search_term'] = $search;
                }
                break;
            case 'view':
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if ($entry != NULL) {
                    Tools::logm('view link #' . $id);
                    $content = $entry['content'];
                    if (function_exists('tidy_parse_string')) {
                        $tidy = tidy_parse_string($content, array('indent'=>true, 'show-body-only' => true), 'UTF8');
                        $tidy->cleanRepair();
                        $content = $tidy->value;
                    }

                    # flattr checking
                    $flattr = new FlattrItem();
                    $flattr->checkItem($entry['url'], $entry['id']);

                    # tags
                    $tags = $this->store->retrieveTagsByEntry($entry['id']);

                    $tpl_vars = array(
                        'entry' => $entry,
                        'content' => $content,
                        'flattr' => $flattr,
                        'tags' => $tags
                    );
                }
                else {
                    Tools::logm('error in view call : entry is null');
                }
                break;
            default: # home, favorites, archive and tag views
                $tpl_vars = array(
                    'entries' => '',
                    'page_links' => '',
                    'nb_results' => '',
                    'listmode' => (isset($_COOKIE['listmode']) ? true : false),
                );

                //if id is given - we retrive entries by tag: id is tag id
                if ($id) {
                  $tpl_vars['tag'] = $this->store->retrieveTag($id, $this->user->getId());
                  $tpl_vars['id'] = intval($id);
                }

                $count = $this->store->getEntriesByViewCount($view, $this->user->getId(), $id);

                if ($count > 0) {
                    $this->pagination->set_total($count);
                    $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                        $this->pagination->page_links('?view=' . $view . '&sort=' . $_SESSION['sort'] . (($id)?'&id='.$id:'') . '&' ));
                    $tpl_vars['entries'] = $this->store->getEntriesByView($view, $this->user->getId(), $this->pagination->get_limit(), $id);
                    $tpl_vars['page_links'] = $page_links;
                    $tpl_vars['nb_results'] = $count;
                }
                Tools::logm('display ' . $view . ' view');
                break;
        }

        return $tpl_vars;
    }

    /**
     * update the password of the current user.
     * if MODE_DEMO is TRUE, the password can't be updated.
     * @todo add the return value
     * @todo set the new password in function header like this updatePassword($newPassword)
     * @return boolean
     */
    public function updatePassword()
    {
        if (MODE_DEMO) {
            $this->messages->add('i', _('in demo mode, you can\'t update your password'));
            Tools::logm('in demo mode, you can\'t do this');
            Tools::redirect('?view=config');
        }
        else {
            if (isset($_POST['password']) && isset($_POST['password_repeat'])) {
                if ($_POST['password'] == $_POST['password_repeat'] && $_POST['password'] != "") {
                    $this->messages->add('s', _('your password has been updated'));
                    $this->store->updatePassword($this->user->getId(), Tools::encodeString($_POST['password'] . $this->user->getUsername()));
                    Session::logout();
                    Tools::logm('password updated');
                    Tools::redirect();
                }
                else {
                    $this->messages->add('e', _('the two fields have to be filled & the password must be the same in the two fields'));
                    Tools::redirect('?view=config');
                }
            }
        }
    }

    public function updateTheme()
    {
        # no data
        if (empty($_POST['theme'])) {
        }

        # we are not going to change it to the current theme...
        if ($_POST['theme'] == $this->getTheme()) {
            $this->messages->add('w', _('still using the "' . $this->getTheme() . '" theme!'));
            Tools::redirect('?view=config');
        }

        $themes = $this->getInstalledThemes();
        $actualTheme = false;

        foreach (array_keys($themes) as $theme) {
            if ($theme == $_POST['theme']) {
                $actualTheme = true;
                break;
            }
        }

        if (! $actualTheme) {
            $this->messages->add('e', _('that theme does not seem to be installed'));
            Tools::redirect('?view=config');
        }

        $this->store->updateUserConfig($this->user->getId(), 'theme', $_POST['theme']);
        $this->messages->add('s', _('you have changed your theme preferences'));

        $currentConfig = $_SESSION['poche_user']->config;
        $currentConfig['theme'] = $_POST['theme'];

        $_SESSION['poche_user']->setConfig($currentConfig);

        $this->emptyCache();

        Tools::redirect('?view=config');
    }

    public function updateLanguage()
    {
        # no data
        if (empty($_POST['language'])) {
        }

        # we are not going to change it to the current language...
        if ($_POST['language'] == $this->getLanguage()) {
            $this->messages->add('w', _('still using the "' . $this->getLanguage() . '" language!'));
            Tools::redirect('?view=config');
        }

        $languages = $this->getInstalledLanguages();
        $actualLanguage = false;

        foreach ($languages as $language) {
            if ($language['value'] == $_POST['language']) {
                $actualLanguage = true;
                break;
            }
        }

        if (! $actualLanguage) {
            $this->messages->add('e', _('that language does not seem to be installed'));
            Tools::redirect('?view=config');
        }

        $this->store->updateUserConfig($this->user->getId(), 'language', $_POST['language']);
        $this->messages->add('s', _('you have changed your language preferences'));

        $currentConfig = $_SESSION['poche_user']->config;
        $currentConfig['language'] = $_POST['language'];

        $_SESSION['poche_user']->setConfig($currentConfig);

        $this->emptyCache();

        Tools::redirect('?view=config');
    }
    /**
     * get credentials from differents sources
     * it redirects the user to the $referer link
     * @return array
     */
    private function credentials() {
        if(isset($_SERVER['PHP_AUTH_USER'])) {
            return array($_SERVER['PHP_AUTH_USER'],'php_auth',true);
        }
        if(!empty($_POST['login']) && !empty($_POST['password'])) {
            return array($_POST['login'],$_POST['password'],false);
        }
        if(isset($_SERVER['REMOTE_USER'])) {
            return array($_SERVER['REMOTE_USER'],'http_auth',true);
        }

        return array(false,false,false);
    }

    /**
     * checks if login & password are correct and save the user in session.
     * it redirects the user to the $referer link
     * @param  string $referer the url to redirect after login
     * @todo add the return value
     * @return boolean
     */
    public function login($referer)
    {
        list($login,$password,$isauthenticated)=$this->credentials();
        if($login === false || $password === false) {
            $this->messages->add('e', _('login failed: you have to fill all fields'));
            Tools::logm('login failed');
            Tools::redirect();
        }
        if (!empty($login) && !empty($password)) {
            $user = $this->store->login($login, Tools::encodeString($password . $login), $isauthenticated);
            if ($user != array()) {
                # Save login into Session
                $longlastingsession = isset($_POST['longlastingsession']);
                $passwordTest = ($isauthenticated) ? $user['password'] : Tools::encodeString($password . $login);
                Session::login($user['username'], $user['password'], $login, $passwordTest, $longlastingsession, array('poche_user' => new User($user)));
                $this->messages->add('s', _('welcome to your wallabag'));
                Tools::logm('login successful');
                Tools::redirect($referer);
            }
            $this->messages->add('e', _('login failed: bad login or password'));
            Tools::logm('login failed');
            Tools::redirect();
        }
    }

    /**
     * log out the poche user. It cleans the session.
     * @todo add the return value
     * @return boolean
     */
    public function logout()
    {
        $this->user = array();
        Session::logout();
        Tools::logm('logout');
        Tools::redirect();
    }

    /**
     * import datas into your poche
     * @return boolean
     */
    public function import() {

      if (!defined('IMPORT_LIMIT')) {
        define('IMPORT_LIMIT', 5);
      }
      if (!defined('IMPORT_DELAY')) {
      	define('IMPORT_DELAY', 5);
      }

      if ( isset($_FILES['file']) ) {
        // assume, that file is in json format
        $str_data = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($str_data, true);

        if ( $data === null ) {
          //not json - assume html
          $html = new simple_html_dom();
          $html->load_file($_FILES['file']['tmp_name']);
          $data = array();
          $read = 0;
          foreach (array('ol','ul') as $list) {
            foreach ($html->find($list) as $ul) {
            	foreach ($ul->find('li') as $li) {
            	  $tmpEntry = array();
            		$a = $li->find('a');
            		$tmpEntry['url'] = $a[0]->href;
            		$tmpEntry['tags'] = $a[0]->tags;
            		$tmpEntry['is_read'] = $read;
            		if ($tmpEntry['url']) {
            		  $data[] = $tmpEntry;
            		}
            	}
            	# the second <ol/ul> is for read links
            	$read = ((sizeof($data) && $read)?0:1);
            }
          }
        }

        //for readability structure
        foreach ($data as $record) {
          if (is_array($record)) {
            $data[] = $record;
            foreach ($record as $record2) {
              if (is_array($record2)) {
              	$data[] = $record2;
              }
            }
          }
        }

        $i = 0; //counter for articles inserted
        foreach ($data as $record) {
          $url = trim( isset($record['article__url']) ? $record['article__url'] : (isset($record['url']) ? $record['url'] : '') );
          if ( $url ) {
            $title = (isset($record['title']) ? $record['title'] :  _('Untitled - Import - ').'</a> <a href="./?import">'._('click to finish import').'</a><a>');
            $body = (isset($record['content']) ? $record['content'] : '');
            $isRead = (isset($record['is_read']) ? intval($record['is_read']) : (isset($record['archive'])?intval($record['archive']):0));
            $isFavorite = (isset($record['is_fav']) ? intval($record['is_fav']) : (isset($record['favorite'])?intval($record['favorite']):0) );
            //insert new record
            $id = $this->store->add($url, $title, $body, $this->user->getId(), $isFavorite, $isRead);
            if ( $id ) {
              //increment no of records inserted
              $i++;
              if ( isset($record['tags']) && trim($record['tags']) ) {
              	//@TODO: set tags

              }
            }
          }
        }

        if ( $i > 0 ) {
          $this->messages->add('s', _('Articles inserted: ').$i._('. Please note, that some may be marked as "read".'));
        }
      }
      //file parsing finished here

      //now download article contents if any

      //check if we need to download any content
      $recordsDownloadRequired = $this->store->retrieveUnfetchedEntriesCount($this->user->getId());
      if ( $recordsDownloadRequired == 0 ) {
        //nothing to download
        $this->messages->add('s', _('Import finished.'));
        Tools::redirect();
      }
      else {
        //if just inserted - don't download anything, download will start in next reload
        if ( !isset($_FILES['file']) ) {
          //download next batch
          $items = $this->store->retrieveUnfetchedEntries($this->user->getId(), IMPORT_LIMIT);

          $config = HTMLPurifier_Config::createDefault();
          $config->set('Cache.SerializerPath', CACHE);
          $purifier = new HTMLPurifier($config);

          foreach ($items as $item) {
          	$url = new Url(base64_encode($item['url']));
          	$content = Tools::getPageContent($url);

          	$title = (($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled'));
          	$body = (($content['rss']['channel']['item']['description'] != '') ? $content['rss']['channel']['item']['description'] : _('Undefined'));

          	//clean content to prevent xss attack
          	$title = $purifier->purify($title);
          	$body = $purifier->purify($body);

          	$this->store->updateContentAndTitle($item['id'], $title, $body, $this->user->getId());
          }

        }
      }

      return array('includeImport'=>true, 'import'=>array('recordsDownloadRequired'=>$recordsDownloadRequired, 'recordsUnderDownload'=> IMPORT_LIMIT, 'delay'=> IMPORT_DELAY * 1000) );
    }

    /**
     * export poche entries in json
     * @return json all poche entries
     */
    public function export()
    {
		$filename = "wallabag-export-".$this->user->getId()."-".date("Y-m-d").".json";
		header('Content-Disposition: attachment; filename='.$filename);

        $entries = $this->store->retrieveAll($this->user->getId());
        echo $this->tpl->render('export.twig', array(
            'export' => Tools::renderJson($entries),
        ));
        Tools::logm('export view');
    }

    /**
     * Checks online the latest version of poche and cache it
     * @param  string $which 'prod' or 'dev'
     * @return string        latest $which version
     */
    private function getPocheVersion($which = 'prod')
    {
        $cache_file = CACHE . '/' . $which;
        $check_time = time();

        # checks if the cached version file exists
        if (file_exists($cache_file) && (filemtime($cache_file) > (time() - 86400 ))) {
           $version = file_get_contents($cache_file);
           $check_time = filemtime($cache_file);
        } else {
           $version = file_get_contents('http://static.wallabag.org/versions/' . $which);
           file_put_contents($cache_file, $version, LOCK_EX);
        }
        return array($version, $check_time);
    }

    public function generateToken()
    {
        if (ini_get('open_basedir') === '') {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			echo 'This is a server using Windows!';
			// alternative to /dev/urandom for Windows
			$token = substr(base64_encode(uniqid(mt_rand(), true)), 0, 20);
			} else {
			$token = substr(base64_encode(file_get_contents('/dev/urandom', false, null, 0, 20)), 0, 15);
			}
        }
        else {
            $token = substr(base64_encode(uniqid(mt_rand(), true)), 0, 20);
        }

        $token = str_replace('+', '', $token);
        $this->store->updateUserConfig($this->user->getId(), 'token', $token);
        $currentConfig = $_SESSION['poche_user']->config;
        $currentConfig['token'] = $token;
        $_SESSION['poche_user']->setConfig($currentConfig);
        Tools::redirect();
    }

    public function generateFeeds($token, $user_id, $tag_id, $type = 'home')
    {
        $allowed_types = array('home', 'fav', 'archive', 'tag');
        $config = $this->store->getConfigUser($user_id);

        if ($config == null) {
            die(_('User with this id (' . $user_id . ') does not exist.'));
        }

        if (!in_array($type, $allowed_types) ||
            $token != $config['token']) {
            die(_('Uh, there is a problem while generating feeds.'));
        }
        // Check the token

        $feed = new FeedWriter(RSS2);
        $feed->setTitle('wallabag — ' . $type . ' feed');
        $feed->setLink(Tools::getPocheUrl());
        $feed->setChannelElement('pubDate', date(DATE_RSS , time()));
        $feed->setChannelElement('generator', 'wallabag');
        $feed->setDescription('wallabag ' . $type . ' elements');

        if ($type == 'tag') {
            $entries = $this->store->retrieveEntriesByTag($tag_id, $user_id);
        }
        else {
            $entries = $this->store->getEntriesByView($type, $user_id);
        }

        if (count($entries) > 0) {
            foreach ($entries as $entry) {
                $newItem = $feed->createNewItem();
                $newItem->setTitle($entry['title']);
                $newItem->setLink($entry['url']);
                $newItem->setDate(time());
                $newItem->setDescription($entry['content']);
                $feed->addItem($newItem);
            }
        }

        $feed->genarateFeed();
        exit;
    }

    public function emptyCache() {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(CACHE, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        Tools::logm('empty cache');
        $this->messages->add('s', _('Cache deleted.'));
        Tools::redirect();
    }
}
