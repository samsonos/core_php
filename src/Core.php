<?php declare(strict_types=1);
/*
 * This file is part of the SamsonPHP\Core package.
 * (c) 2013 Vitaly Iegorov <egorov@samsonos.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace samson\core;

use samsonframework\container\Builder;
use samsonframework\container\metadata\ClassMetadata;
use samsonframework\container\metadata\MethodMetadata;
use samsonframework\core\SystemInterface;
use samsonframework\di\ContainerInterface;
use samsonframework\resource\ResourceMap;
use samsonphp\config\Scheme;
use samsonphp\core\exception\CannotLoadModule;
use samsonphp\core\exception\ViewPathNotFound;
use samsonphp\event\Event;
use samsonframework\container\ContainerBuilderInterface;

/**
 * SamsonPHP Core.
 *
 * @author Vitaly Iegorov <egorov@samsonos.com>
 */
class Core implements SystemInterface
{
    /** @var ContainerInterface */
    protected $container;

    /** @var ClassMetadata[] */
    protected $metadataCollection = [];

    /** @var ContainerBuilderInterface */
    protected $builder;

    /** @var string Current system environment */
    protected $environment;

    /* Rendering models */
    /** @deprecated Standard algorithm for view rendering */
    const RENDER_STANDART = 1;
    /** @deprecated View rendering algorithm from array of view variables */
    const RENDER_VARIABLE = 3;

    /** @deprecated @var  ResourceMap Current web-application resource map */
    public $map;
   
    /** @deprecated @var string Path to current web-application */
    public $system_path = __SAMSON_CWD__;
    /** @deprecated @var string View path loading mode */
    public $render_mode = self::RENDER_STANDART;
    /** @var Module Pointer to current active module */
    protected $active = null;
    /** @var bool Flag for outputting layout template, used for asynchronous requests */
    protected $async = false;
    /** @var string Path to main system template */
    protected $template_path = __SAMSON_DEFAULT_TEMPLATE;

    /** @return ContainerInterface Get system container */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Core constructor.
     *
     * @param ContainerBuilderInterface $builder Container builder
     * @param ResourceMap|null $map system resources
     */
    public function __construct(ContainerBuilderInterface $builder, ResourceMap $map = null)
    {
        $this->builder = $builder;

        if (!isset($map)) {
            // Get correct web-application path
            $this->system_path = __SAMSON_CWD__;

            // Get web-application resource map
            $this->map = ResourceMap::get($this->system_path, false, array('src/'));
        } else { // Use data from passed map
            $this->map = $map;
            $this->system_path = $map->entryPoint;
        }

        // Temporary add template worker
        $this->subscribe('core.rendered', array($this, 'generateTemplate'));

        // TODO: Shoud be configurable not fixed integration
        $whoops = new \Whoops\Run;
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        $whoops->register();

        // Fire core creation event
        Event::fire('core.created', array(&$this));

        // Signal core configure event
        Event::signal('core.configure', array($this->system_path . __SAMSON_CONFIG_PATH));
    }

    /**
     * Generic wrap for Event system subscription.
     * @see \samson\core\\samsonphp\event\Event::subscribe()
     *
     * @param string   $key     Event identifier
     * @param callable $handler Event handler
     * @param array    $params  Event parameters
     *
     * @return $this Chaining
     */
    public function subscribe($key, $handler, $params = array())
    {
        Event::subscribe($key, $handler, $params);

        return $this;
    }

    /**
     * Change current system working environment or receive
     * current system enviroment if no arguments are passed.
     *
     * @param string $environment Environment identifier
     *
     * TODO: Function has two different logics - needs to be changed!
     * @return $this|string Chaining or current system environment
     */
    public function environment($environment = Scheme::BASE)
    {
        if (func_num_args() !== 0) {
            $this->environment = $environment;

            // Signal core environment change
            Event::signal('core.environment.change', array($environment, &$this));
            return $this;
        }

        return $this->environment;
    }

    /**
     * Generate special response header triggering caching mechanisms
     * @param int $cacheLife Amount of seconds for cache(default 3600 - 1 hour)
     * @param string $accessibility Cache-control accessibility value(default public)
     */
    public function cached($cacheLife = 3600, $accessibility = 'public')
    {
        static $cached;
        // Protect sending cached headers once
        if (!isset($cached) or $cached !== true) {
            header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + $cacheLife));
            header('Cache-Control: ' . $accessibility . ', max-age=' . $cacheLife);
            header('Pragma: cache');

            $cached = true;
        }
    }

    /**
     * Set asynchronous mode.
     * This mode will not output template and will just path everything that
     * was outputted to client.
     *
     * @param bool $async True to switch to asynchronous output mode
     *
     * @return $this Chaining
     */
    public function async($async)
    {
        $this->async = $async;

        return $this;
    }

    /** @see iCore::path() */
    public function path($path = null)
    {
        // Если передан аргумент
        if (func_num_args()) {
            // Сформируем новый относительный путь к главному шаблону системы
            $this->template_path = $path . $this->template_path;

            // Сохраним относительный путь к Веб-приложению
            $this->system_path = $path;

            // Продолжил цепирование
            return $this;
        }

        // Вернем текущее значение
        return $this->system_path;
    }

    /**    @see iModule::active() */
    public function &active(iModule &$module = null)
    {
        // Сохраним старый текущий модуль
        $old = &$this->active;

        // Если передано значение модуля для установки как текущий - проверим и установим его
        if (isset($module)) {
            $this->active = &$module;
        }

        // Вернем значение текущего модуля
        return $old;
    }

    /**
     * Retrieve module instance by identifier.
     *
     * @param string|null $module Module identifier
     *
     * @return null|Module Found or active module
     */
    public function &module($module = null)
    {
        $return = null;

        // Ничего не передано - вернем текущуй модуль системы
        if (!isset($module) && isset($this->active)) {
            $return = &$this->active;
        } elseif (is_object($module)) {
            $return = &$module;
        } elseif (is_string($module)) {
            $return = $this->container->get($module);
        }

        // Ничего не получилось вернем ошибку
        if ($return === null) {
            e('Не возможно получить модуль(##) системы', E_SAMSON_CORE_ERROR, array($module));
        }

        return $return;
    }

    /**
     * Unload module from core.
     *
     * @param string $moduleID Module identifier
     */
    public function unload($moduleID)
    {
        if (isset($this->module_stack[$moduleID])) {
            unset($this->module_stack[$moduleID]);
        }
    }

    /**
     * Insert generic html template tags and data
     *
     * @param string $templateHtml Generated HTML
     *
     * @deprecated Must be moved to a new HTML output object
     * @return mixed Changed HTML template
     */
    public function generateTemplate(&$templateHtml)
    {
        // Добавим путь к ресурсам для браузера
        $headHtml = "\n" . '<base href="' . url()->base() . '">';
        // Добавим отметку времени для JavaScript
        $headHtml .= "\n" . '<script type="text/javascript">var __SAMSONPHP_STARTED = new Date().getTime();</script>';

        // Добавим поддержку HTML для старых IE
        $headHtml .= "\n" . '<!--[if lt IE 9]>';
        $headHtml .= "\n" . '<script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>';
        $headHtml .= "\n" . '<script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>';
        $headHtml .= "\n" . '<![endif]-->';

        // Выполним вставку главного тега <base> от которого зависят все ссылки документа
        // также подставим МЕТА-теги для текущего модуля и сгенерированный минифицированный CSS
        $templateHtml = str_ireplace('<head>', '<head>' . $headHtml, $templateHtml);

        // Вставим указатель JavaScript ресурсы в конец HTML документа
        $templateHtml = str_ireplace('</html>', '</html>' . __SAMSON_COPYRIGHT, $templateHtml);

        return $templateHtml;
    }

    /**
     * Start SamsonPHP framework.
     *
     * @param string $default Default module identifier
     *
     * @throws ViewPathNotFound
     */
    public function start($default)
    {
        // TODO: Change ExternalModule::init() signature
        // Fire core started event
        Event::fire('core.started');

        // TODO: Does not see why it should be here
        // Set main template path
        $this->template($this->template_path);

        // Security layer
        $securityResult = true;
        // Fire core security event
        Event::fire('core.security', array(&$this, &$securityResult));

        /** @var mixed $result External route controller action result */
        $result = false;

        // If we have passed security application layer
        if ($securityResult) {
            // Fire core routing event - go to routing application layer
            Event::signal('core.routing', array(&$this, &$result, $default));
        }

        // If no one has passed back routing callback
        if (!isset($result) || $result === false) {
            // Fire core e404 - routing failed event
            $result = Event::signal('core.e404', array(url()->module, url()->method));
        }

        // Response
        $output = '';

        // If this is not asynchronous response and controller has been executed
        if (!$this->async && ($result !== false)) {
            // Store module data
            $data = $this->active->toView();

            // Render main template
            $output = $this->render($this->template_path, $data);

            // Fire after render event
            Event::fire('core.rendered', array(&$output));
        }

        // Output results to client
        echo $output;

        // Fire ended event
        Event::fire('core.ended', array(&$output));
    }

    /**	@see iCore::template() */
    public function template( $template = NULL, $absolutePath = false )
    {
        // Если передан аргумент
        if( func_num_args() ){
            $this->template_path = ($absolutePath)?$template:$this->active->path().$template;
        }

        // Аргументы не переданы - вернем текущий путь к шаблону системы
        return $this->template_path;
    }

    /**
     * Render file to a buffer.
     *
     * @param string $view Path to file
     * @param array  $data Collection of variables to path to file
     *
     * @return string Rendered file contents
     * @throws ViewPathNotFound
     */
    public function render($view, $data = array())
    {
        // TODO: Make rendering as external system, to split up these 3 rendering options

        // Объявить ассоциативный массив переменных в данном контексте
        if (is_array($data)) {
            extract($data);
        }

        // Начать вывод в буффер
        ob_start();

        // Path to another template view, by default we are using default template folder path,
        // for meeting first condition
        $templateView = $view;

        if (locale() != SamsonLocale::DEF) {
            // Modify standard view path with another template
            $templateView = str_replace(__SAMSON_VIEW_PATH, __SAMSON_VIEW_PATH . locale() . '/', $templateView);
        }

        // Depending on core view rendering model
        switch ($this->render_mode) {
            // Standard algorithm for view rendering
            case self::RENDER_STANDART:
                // Trying to find another template path, by default it's an default template path
                if (file_exists($templateView)) {
                    include($templateView);
                } elseif (file_exists($view)) {
                    // If another template wasn't found - we will use default template path
                    include($view);
                } else { // Error no template view was found
                    throw(new ViewPathNotFound($view));
                }
                break;

            // View rendering algorithm from array of view variables
            case self::RENDER_VARIABLE:
                // Collection of views
                $views = &$GLOBALS['__compressor_files'];
                // Trying to find another template path, by default it's an default template path
                if (isset($views[$templateView])) {
                    eval(' ?>' . $views[$templateView] . '<?php ');
                } elseif (isset($views[$view])) {
                    // If another template wasn't found - we will use default template path
                    eval(' ?>' . $views[$view] . '<?php ');
                } else { // Error no template view was found
                    throw(new ViewPathNotFound($view));
                }
                break;
        }

        // Получим данные из буффера вывода
        $html = ob_get_contents();

        // Очистим буффер
        ob_end_clean();

        // Fire core render event
        Event::fire('core.render', array(&$html, &$data, &$this->active));

        ////elapsed('End rendering '.$__view);
        return $html;
    }

    //[PHPCOMPRESSOR(remove,start)]

    /**
     * Load system from composer.json
     * @param string $dependencyFilePath Path to dependencies file
     * @return $this Chaining
     */
    public function composer($dependencyFilePath = null)
    {
        $composerModules = array();

        Event::fire(
            'core.composer.create',
            array(
                &$composerModules,
                isset($dependencyFilePath) ? $dependencyFilePath : $this->system_path,
                array(
                    'vendorsList' => array('samsonphp/', 'samsonos/', 'samsoncms/', 'samsonjavascript/'),
                    'ignoreKey' => 'samson_module_ignore',
                    'includeKey' => 'samson_module_include'
                )
            )
        );

        $modulesToLoad = [];

        // Iterate requirements
        foreach ($composerModules as $requirement => $parameters) {
            $moduleName = $this->load(__SAMSON_CWD__ . __SAMSON_VENDOR_PATH . $requirement,
            array_merge(
                is_array($parameters) ? $parameters : array($parameters),
                array('module_id' => $requirement)
            ));

            $modulesToLoad[$moduleName] = $parameters;
        }

        $localModulesPath = '../src';
        ResourceMap::get('cache');
        // TODO: Nested modules relation
        for ($i = 0; $i < 2; $i++) {
            $resourceMap = ResourceMap::get($localModulesPath);

            foreach ($resourceMap->modules as $moduleFile) {
                $modulePath = str_replace(realpath($localModulesPath), '', $moduleFile[1]);
                $modulePath = explode('/', $modulePath);
                $modulePath = $localModulesPath . '/' . $modulePath[1];
                $moduleName = $this->load($modulePath, $parameters);
                $modulesToLoad[$moduleName] = $parameters;
            }
        }

        //$this->active = new VirtualModule($this->system_path, $this->map, $this, 'local');

        // Create local module and set it as active
        $this->createMetadata(VirtualModule::class, 'local', $this->system_path);

        // TODO: This should be changed to one single logic
        // Require all local module model files
        foreach ($this->map->models as $model) {
            // TODO: Why have to require once?
            require_once($model);
        }

        // Create all local modules
        foreach ($this->map->controllers as $controller) {
            // Require class into PHP
            require($controller);

            //new VirtualModule($this->system_path, $this->map, $this, basename($controller, '.php'));

            $this->createMetadata(VirtualModule::class, basename($controller, '.php'), $this->system_path);
        }

        $this->createMetadata(get_class($this), get_class($this), $this->system_path);

        $metadata = new ClassMetadata();
        $metadata->className = get_class($this);
        $metadata->name = get_class($this);
        $metadata->scopes[] = Builder::SCOPE_SERVICES;
        $metadata->methodsMetadata['__construct'] = new MethodMetadata($metadata);
        $metadata->methodsMetadata['__construct']->dependencies['map'] = ResourceMap::class;

        $this->metadataCollection[$metadata->name] = $metadata;

        $metadata = new ClassMetadata();
        $metadata->className = ResourceMap::class;
        $metadata->name = ResourceMap::class;
        $metadata->scopes[] = Builder::SCOPE_SERVICES;

        $this->metadataCollection[$metadata->name] = $metadata;
        $containerPath = $this->path().'www/cache/Container.php';

        file_put_contents($containerPath, $this->builder->build($this->metadataCollection));

        require_once($containerPath);

        $this->container = new \Container();
        $containerReflection = new \ReflectionClass(get_class($this->container));
        $serviceProperty = $containerReflection->getProperty('servicesInstances');
        $serviceProperty->setAccessible(true);
        $containerServices = $serviceProperty->getValue($this->container);
        $containerServices[get_class($this)] = $this;
        $serviceProperty->setValue(null, $containerServices);
        $serviceProperty->setAccessible(false);

        foreach ($modulesToLoad as $name => $parameters) {
            $instance = $this->container->get($name);
            $this->initModule($instance, $parameters);
        }

        $this->active = $this->container->getLocal();

        return $this;
    }

    /**
     * Initialize module.
     *
     * @param ExternalModule $instance           Module instance for initialization
     * @param array          $composerParameters Collection of extra parameters from composer.json file
     */
    protected function initModule($instance, $composerParameters)
    {
        $identifier = $instance->id();

        // Set composer parameters
        $instance->composerParameters = $composerParameters;

        // TODO: Change event signature to single approach
        // Fire core module load event
        Event::fire('core.module_loaded', array($identifier, &$instance));

        // Signal core module configure event
        Event::signal('core.module.configure', array(&$instance, $identifier));

        // Call module preparation handler
        if (!$instance->prepare()) {
            // Handle module failed preparing
        }

        // Trying to find parent class for connecting to it to use View/Controller inheritance
//        $parentClass = get_parent_class($instance);
//        if (!in_array($parentClass,
//            array('samson\core\ExternalModule', 'samson\core\CompressableExternalModule'))
//        ) {
//            // Переберем загруженные в систему модули
//            foreach ($this->module_stack as &$m) {
//                // Если в систему был загружен модуль с родительским классом
//                if (get_class($m) === $parentClass) {
//                    $instance->parent = &$m;
//                    //elapsed('Parent connection for '.$moduleClass.'('.$connector->uid.') with '.$parent_class.'('.$m->uid.')');
//                }
//            }
//        }
    }

    /**
     * Load module from path to core.
     *
     * @param string $path       Path for module loading
     * @param array  $parameters Collection of loading parameters
     *
     * @return string module name
     * @throws \samsonphp\core\exception\CannotLoadModule
     */
    public function load($path, $parameters = array())
    {
        $name = '';
        // Check path
        if (file_exists($path)) {
            /** @var ResourceMap $resourceMap Gather all resources from path */
            $resourceMap = ResourceMap::get($path);
            if (isset($resourceMap->module[0])) {

                /** @var string $controllerPath Path to module controller file */
                $controllerPath = $resourceMap->module[1];

                /** @var string $moduleClass Name of module controller class to load */
                $moduleClass = $resourceMap->module[0];

                // Require module controller class into PHP
                if (file_exists($controllerPath)) {
                    require_once($controllerPath);
                }

                // TODO: this should be done via composer autoload file field
                // Iterate all function-style controllers and require them
                foreach ($resourceMap->controllers as $controller) {
                    require_once($controller);
                }

                $reflection = new \ReflectionClass($moduleClass);
                $name = $reflection->getDefaultProperties();
                $name = $name['id'] ?? str_replace('/', '', $moduleClass);

                $this->createMetadata($moduleClass, $name, $path);

                /*$this->initModule(
                    new $moduleClass($path, $resourceMap, $this),
                    $parameters
                );*/
            } elseif (is_array($parameters) && isset($parameters['samsonphp_package_compressable']) && ($parameters['samsonphp_package_compressable'] == 1)) {
                $name = str_replace('/', '', $parameters['module_id']);
                $this->createMetadata(VirtualModule::class, str_replace('/', '', $parameters['module_id']), $path);

                /*$this->initModule(
                    new VirtualModule($path, $resourceMap, $this, str_replace('/', '', $parameters['module_id'])),
                    $parameters
                );*/
            }
//            elseif (count($resourceMap->classes)) {
//                /** Update for future version: Search classes that implement LoadableInterface */
//                foreach ($resourceMap->classes as $classPath => $class) {
//                    // This class implements LoadableInterface LoadableInterface::class
//                    if (in_array('\samsonframework\core\LoadableInterface', $resourceMap->classData[$classPath]['implements'])) {
//
//                        $name =  str_replace('/', '', $parameters['module_id']);
//
//                        $this->createMetadata(VirtualModule::class, str_replace('/', '', $parameters['module_id']), $path);
//
//                        /*$this->initModule(
//                            new VirtualModule(
//                                $path,
//                                $resourceMap,
//                                $this,
//                                str_replace('/', '', $resourceMap->classData[$classPath]['className'])
//                            ),
//                            $parameters
//                        );*/
//                    }
//                }
//            }

        } else {
            throw new CannotLoadModule($path);
        }

        return $name;
    }
    //[PHPCOMPRESSOR(remove,end)]

    /** Магический метод для десериализации объекта */
    public function __wakeup()
    {
        $this->active = &$this->module_stack['local'];
    }

    /** Магический метод для сериализации объекта */
    public function __sleep()
    {
        return array('module_stack', 'render_mode');
    }

    protected function createMetadata($class, $name, $path)
    {
        $metadata = new ClassMetadata();
        $class = ltrim($class, '\\');
        $metadata->className = $class;
        $metadata->name = str_replace('/', '', $name ?? $class);
        $metadata->scopes[] = Builder::SCOPE_SERVICES;
        $metadata->methodsMetadata['__construct'] = new MethodMetadata($metadata);
        $metadata->methodsMetadata['__construct']->dependencies['path'] = $path;
        $metadata->methodsMetadata['__construct']->dependencies['resources'] = ResourceMap::class;
        $metadata->methodsMetadata['__construct']->dependencies['system'] = get_class($this);

        $this->metadataCollection[$metadata->name] = $metadata;
    }
}
