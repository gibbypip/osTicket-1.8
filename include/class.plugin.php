<?php

require_once(INCLUDE_DIR.'/class.config.php');
class PluginConfig extends Config {
    var $table = CONFIG_TABLE;
    var $form;

    function PluginConfig($name) {
        parent::Config("plugin.$name");
    }

    /* abstract */
    function getOptions() {
        return array();
    }

    function getForm() {
        if (!isset($this->form)) {
            $this->form = new Form($this->getOptions());
            if ($_SERVER['REQUEST_METHOD'] != 'POST')
                $this->form->data($this->getInfo());
        }
        return $this->form;
    }

    /**
     * commit
     *
     * Used in the POST request of the configuration process. The
     * ::getConfigurationForm() method should be used to retrieve a
     * configuration form for this plugin. That form should be submitted via
     * a POST request, and this method should be called in that request. The
     * data from the POST request will be interpreted and will adjust the
     * configuration of this field
     *
     * Parameters:
     * errors - (OUT array) receives validation errors of the parsed
     *      configuration form
     *
     * Returns:
     * (bool) true if the configuration was updated, false if there were
     * errors. If false, the errors were written into the received errors
     * array.
     */
    function commit(&$errors=array()) {
        $f = $this->getForm();
        if ($f->isValid()) {
            $config = $f->getClean();
            $this->pre_save($config, $errors);
        }
        $errors += $f->errors();
        if (count($errors) === 0)
            return $this->updateAll($config);
        return false;
    }

    /**
     * Pre-save hook to check configuration for errors (other than obvious
     * validation errors) prior to saving
     */
    function pre_save($config, &$errors) {
        return;
    }

    /**
     * Remove all configuration for this plugin -- used when the plugin is
     * uninstalled
     */
    function purge() {
        $sql = 'DELETE FROM '.$this->table
            .' WHERE `namespace`='.db_input($this->getNamespace());
        return (db_query($sql) && db_affected_rows());
    }
}

class PluginManager {
    static private $plugin_info = array();

    /**
     * boostrap
     *
     * Used to bootstrap the plugin subsystem and initialize all the plugins
     * currently enabled.
     */
    function bootstrap() {
        foreach ($this->allActive() as $p)
            $p->bootstrap();
    }

    /**
     * allActive
     *
     * Scans the plugin registry to find all installed and active plugins.
     * Those plugins are included, instanciated, and cached in a list.
     *
     * Returns:
     * Array<Plugin> a cached list of instanciated plugins for all installed
     * and active plugins
     */
    static function allInstalled() {
        static $plugins = null;
        if ($plugins !== null)
            return $plugins;

        $plugins = array();
        $sql = 'SELECT * FROM '.PLUGIN_TABLE;
        if (!($res = db_query($sql)))
            return $plugins;

        $infos = static::allInfos();
        while ($ht = db_fetch_array($res)) {
            // XXX: Only read active plugins here. allInfos() will
            //      read all plugins
            if (isset($infos[$ht['install_path']])) {
                $info = $infos[$ht['install_path']];
                if ($ht['isactive']) {
                    list($path, $class) = explode(':', $info['plugin']);
                    require_once(INCLUDE_DIR . '/' . $ht['install_path'] . '/' . $path);
                    $plugins[$ht['install_path']] = new $class($ht['id']);
                }
                else {
                    $plugins[$ht['install_path']] = $ht;
                }
            }
        }

        return $plugins;
    }

    static function allActive() {
        $plugins = array();
        foreach (static::allInstalled() as $p)
            if ($p instanceof Plugin && $p->isActive())
                $plugins[] = $p;
        return $plugins;
    }

    /**
     * allInfos
     *
     * Scans the plugin folders for installed plugins. For each one, the
     * plugin.php file is included and the info array returned in added to
     * the list returned.
     *
     * Returns:
     * Information about all available plugins. The registry will have to be
     * queried to determine if the plugin is installed
     */
    static function allInfos() {
        static $defaults = array(
            'include' => 'include/',
            'stream' => false,
        );

        if (static::$plugin_info)
            return static::$plugin_info;

        foreach (glob(INCLUDE_DIR . 'plugins/*', GLOB_ONLYDIR) as $p) {
            if (!is_file($p . '/plugin.php'))
                // Invalid plugin -- must define "/plugin.php"
                continue;
            // plugin.php is require to return an array of informaiton about
            // the plugin.
            $info = array_merge($defaults, (include $p . '/plugin.php'));
            $info['install_path'] = str_replace(INCLUDE_DIR, '', $p);

            // XXX: Ensure 'id' key isset
            static::$plugin_info[$info['install_path']] = $info;
        }
        return static::$plugin_info;
    }

    static function getInfoForPath($path) {
        $infos = static::allInfos();
        if (isset($infos[$path]))
            return $infos[$path];
        return null;
    }

    function getInstance($path) {
        static $instances = array();
        if (!isset($instances[$path])
                && ($ps = static::allInstalled())
                && ($ht = $ps[$path])
                && ($info = static::getInfoForPath($path))) {
            // $ht may be the plugin instance
            if ($ht instanceof Plugin)
                return $ht;
            // Usually this happens when the plugin is being enabled
            list($path, $class) = explode(':', $info['plugin']);
            require_once(INCLUDE_DIR . $info['install_path'] . '/' . $path);
            $instances[$path] = new $class($ht['id']);
        }
        return $instances[$path];
    }

    /**
     * install
     *
     * Used to install a plugin that is in-place on the filesystem, but not
     * registered in the plugin registry -- the %plugin table.
     */
    function install($path) {
        if (!($info = $this->getInfoForPath($path)))
            return false;

        $sql='INSERT INTO '.PLUGIN_TABLE.'SET installed=NOW() '
            .', install_path='.db_input($path)
            .', name='.db_input($info['name']);
        return (db_query($sql) && db_affected_rows());
    }
}

/**
 * Class: Plugin (abstract)
 *
 * Base class for plugins. Plugins should inherit from this class and define
 * the useful pieces of the
 */
class Plugin {
    /**
     * Configuration manager for the plugin. Should be the name of a class
     * that inherits from PluginConfig. This is abstract and must be defined
     * by the plugin subclass.
     */
    var $config_class = null;
    var $id;
    var $info;

    function Plugin($id) {
        $this->id = $id;
        $this->load();
    }

    function load() {
        $sql = 'SELECT * FROM '.PLUGIN_TABLE.' WHERE
            `id`='.db_input($this->id);
        if (($res = db_query($sql)) && ($ht=db_fetch_array($res)))
            $this->ht = $ht;
        $this->info = PluginManager::getInfoForPath($this->ht['install_path']);
    }

    function getId() { return $this->id; }
    function getName() { return $this->info['name']; }
    function isActive() { return $this->ht['isactive']; }
    function isPhar() { return $this->ht['isphar']; }
    function getInstallDate() { return $this->ht['installed']; }

    function getIncludePath() {
        return realpath(INCLUDE_DIR . $this->info['install_path'] . '/'
            . $this->info['include_path']) . '/';
    }

    /**
     * uninstall
     *
     * Removes the plugin from the plugin registry. The files remain on the
     * filesystem which would allow the plugin to be reinstalled. The
     * configuration for the plugin is also removed. If the plugin is
     * reinstalled, it will have to be reconfigured.
     */
    function uninstall() {
        $sql = 'DELETE FROM '.PLUGIN_TABLE
            .' WHERE id='.db_input($this->getId());
        if (db_query($sql) && db_affected_rows())
            return $this->getConfig()->purge();
        return false;
    }

    function enable() {
        $sql = 'UPDATE '.PLUGIN_TABLE
            .' SET isactive=1 WHERE id='.db_input($this->getId());
        return (db_query($sql) && db_affected_rows());
    }

    function disable() {
        $sql = 'UPDATE '.PLUGIN_TABLE
            .' SET isactive=0 WHERE id='.db_input($this->getId());
        return (db_query($sql) && db_affected_rows());
    }

    /**
     * upgrade
     *
     * Upgrade the plugin. This is used to migrate the database pieces of
     * the plugin using the database migration stream packaged with the
     * plugin.
     */
    function upgrade() {
    }

    function getConfig() {
        static $config = null;
        if ($config === null)
            $config = new $this->config_class($this->getId());

        return $config;
    }

    function source($what) {
        $what = str_replace('\\', '/', $what);
        if ($what && $what[0] != '/')
            $what = $this->getIncludePath() . $what;
        include_once $what;
    }

    static function lookup($id) { //Assuming local ID is the only lookup used!
        $path = false;
        if ($id && is_numeric($id)) {
            $sql = 'SELECT install_path FROM '.PLUGIN_TABLE
                .' WHERE id='.db_input($id);
            $path = db_result(db_query($sql));
        }
        if ($path)
           return PluginManager::getInstance($path);
    }
}

?>
