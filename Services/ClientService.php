<?php

namespace MESD\Jasper\ReportBundle\Services;

use JasperClient\Client\Client;
use JasperClient\Client\Report;
use JasperClient\Client\ReportBuilder;
use JasperClient\Client\ReportLoader;

use MESD\Jasper\ReportBundle\Event\ReportFolderOpenEvent;
use MESD\Jasper\ReportBundle\Event\ReportViewerRequestEvent;

use MESD\Jasper\ReportBundle\Exception\JasperNotConnectedException;
use MESD\Jasper\ReportBundle\Factories\InputControlFactory;

use Symfony\Component\DependencyInjection\Container;

/**
 * Service class that acts as a wrapper around the jasper client class in the jasper client library
 */
class ClientService
{
    ///////////////
    // CONSTANTS //
    ///////////////
    
    const DEFAULT_REPORT_FORMAT = 'html';
    const DEFAULT_REPORT_PAGE_NUMBER = 1;
    
    const FALLBACK_ASSET_URL = '';

    //These are the placeholders that are given to the routers generate function, which CANNOT have the '{}' characters Jasper looks for
    const ASSET_ROUTE_CONTEXT_PATH_PLACEHOLDER = 'tempvar-contextPath';
    const ASSET_ROUTE_REPORT_EXECUTION_ID_PLACEHOLDER = 'tempvar-reportExecutionId';
    const ASSET_ROUTE_EXPORT_OPTIONS_PLACEHOLDER = 'tempvar-exportOptions';

    //These are the placeholders that Jasper will look for that will replace the ones placed into the url originally
    const ASSET_ROUTE_CONTEXT_PATH_JASPER_VAR = '{contextPath}';
    const ASSET_ROUTE_REPORT_EXECUTION_ID_JASPER_VAR = '{reportExecutionId}';
    const ASSET_ROUTE_EXPORT_OPTIONS_JASPER_VAR = '{exportOptions}';

    //Error Messages
    const EXCEPTION_OPTIONS_HANDLER_NOT_INTERFACE = 'Requested Options Handler service does not implement Options Handler Interface';

    ///////////////
    // VARIABLES //
    ///////////////

    /**
     * Reference to the jasper client that is initialized by the connect method
     * with the parameters passed via dependency injection
     * @var JasperClient\Client\Client
     */
    private $jasperClient;

    /**
     * Default symfony route to send asset requests to 
     * @var string
     */
    private $defaultAssetRoute;

    /**
     * The Symfony Service Container
     * @var Symfony\Component\DependencyInjection\Container
     */
    private $container;

    /**
     * The Host Name of the report server
     * @var string
     */
    private $reportHost;

    /**
     * Jasper Servers port
     * @var string
     */
    private $reportPort;

    /**
     * Username for the jasper server account
     * @var string
     */
    private $reportUsername;

    /**
     * Password for the jasper server account
     * @var string
     */
    private $reportPassword;

    /**
     * Whether to cache resource lists or not
     * @var boolean
     */
    private $useFolderCache;

    /**
     * Directory of the resource list cache
     * @var string
     */
    private $folderCacheDir;

    /**
     * How long a resource list cache is considered fresh
     * @var int
     */
    private $folderCacheTimeout;

    /**
     * Where to cache reports
     * @var string
     */
    private $reportCacheDir;

    /**
     * The service name of the application specific input control option handler
     * @var string
     */
    private $optionHandlerServiceName;

    /**
     * Default Folder to go to when getting the resource list if no other folder is specified
     * @var string
     */
    private $defaultFolder;

    private $eventDispatcher;
    private $router;
    private $routeHelper;

    /**
     * Whether the client is connected to the jasper server or not
     * @var boolean
     */
    private $connected;


    //////////////////
    // BASE METHODS //
    //////////////////


    /**
     * Constructor used via Symfony's dependency injection container to intialize the needed dependencies
     * 
     * @param Symfony\Component\DependencyInjection\Container $container The Symfony Service Container
     */
    public function __construct(Container $container) {
        //Set stuff
        $this->container = $container;

        //Set connected flag to false until the connect function is able to successfully login
        $this->connected = false;

        
    }


    ///////////////////
    // CLASS METHODS //
    ///////////////////


    /**
     * Connect to the Jasper Report Server with the current set of parameters
     * (This is function is called automatically during the dependency injection container setup)
     * 
     * @return boolean Indicator of whether the connection was successful
     */
    public function connect() {
        //Attempt to initialize the client and login to the report server
        try {
            //Give this object's stored parameters to initialize the jasper client 
            $this->jasperClient = new Client($this->reportHost . ':' . $this->reportPort, $this->reportUsername, $this->reportPassword);

            //Login and set the connection flag to the return of the login method
            $this->connected = $this->jasperClient->login();
        } catch (\Exception $e) {
            //Set the connection status to false
            $this->connected = false;

            //Rethrow the exception
            throw $e;
        }

        //Return the connection flag
        return $this->connected;
    }


    /**
     * Builds a symfony form from the inputs from the requested report uri
     *
     * @param  string $reportUri   The uri of the report whose input controls to construct the form from
     * @param  string $targetRoute The route to serve as the action for the form
     * @param  array  $options     Options array:  
     *                               'getICFrom' => Where to get the control options from
     *                               'routeParameters' => additional parameters to generate the action url with
     *                               'data' => data parameter for the form builder
     *                               'options' => array of options to send to the form builder
     *
     * @return Symfony\Component\Form\Form The input controls form
     */
    public function buildReportInputForm($reportUri, $targetRoute = null, $options = []) {
        //Handle the options array
        //$getICFrom = 'Fallback', $data = null, $options = []
        $routeParameters = (isset($options['routeParameters']) && null != $options['routeParameters']) ? $options['routeParameters'] : array();
        $getICFrom = (isset($options['getICFrom']) && null != $options['getICFrom']) ? $options['getICFrom'] : 'Fallback';
        $data = (isset($options['data']) && null != $options['data']) ? $options['data'] : null;
        $formOptions = (isset($options['options']) && null != $options['options']) ? $options['options'] : array();

        //Get the options handler from the dependency container
        $optionsHandler = $this->container->get($this->optionHandlerServiceName);

        //Check that the options handler implements the option handler interface
        if (!in_array('MESD\Jasper\ReportBundle\Interfaces\OptionsHandlerInterface', class_implements($optionsHandler))) {
            throw new \Exception(self::EXCEPTION_OPTIONS_HANDLER_NOT_INTERFACE);
        }

        //Create a new input control factory
        $icFactory = new InputControlFactory($optionsHandler, $getICFrom, 'MESD\Jasper\ReportBundle\InputControl\\');

        //Load the input controls from the client using the factory and the options handler
        $inputControls = $this->jasperClient->getReportInputControl($reportUri, $getICFrom, $icFactory);

        //Build the form
        $form = $this->container->get('form.factory')->createBuilder('form', $data, $formOptions);
        
        if ($targetRoute) {
            $form->setAction($this->container->get('router')->generate($targetRoute, $routeParameters));
        }
        
        $form->setMethod('POST');
        foreach($inputControls as $inputControl) {
            $inputControl->attachInputToFormBuilder($form);
        }
        $form->add('Run', 'submit');

        //Return the completed form
        return $form->getForm();
    }


    /**
     * Creates a new report builder object with some of the bundle configuration passed in
     *
     * @param  string $resourceUri Uri of the report on the jasper server
     * @param  string $getICFrom   Where to get the input control options from
     *
     * @return FormBuilder         The form builder
     */
    public function createReportBuilder($resourceUri, $getICFrom = 'Fallback') {
        //Get the report builder started from the client
        $reportBuilder = $this->jasperClient->createReportBuilder($resourceUri, $getICFrom);

        //Set the stuff from the bundle configuration
        $reportBuilder->setReportCache($this->reportCacheDir);

        //return the report builder
        return $reportBuilder;
    }


    //Get a folder resource (leavng the argument null returns the default folder)
    public function getFolder($folderUri = null) {
        //If the connection is valid, then try and get the resourceCollection with the given or default folderUri
        if ($this->isConnected()) {
            if ($folderUri) {
                return $this->jasperClient->getFolder($folderUri, $this->useFolderCache, $this->folderCacheDir, $this->folderCacheTimeout);
            } else {
                return $this->jasperClient->getFolder($this->reportDefaultFolder, $this->useFolderCache, $this->folderCacheDir, $this->folderCacheTimeout);
            }
        } else {
            return false;
        }
    }

    //Get a folder view, works like getFolder, but will also look for query parameters in the url and open the subfolder as necessary
    //when used with the twig function, it will automatically handle opening folders
    //Note, getFolderView returns the folder collection wrapped in an array keyed by parent folder uri (to generate links back to the parent)
    public function getFolderView($folderUri = null) {
        if ($this->isConnected()) {
            //If the folder is not specified, set to default
            if (!$folderUri) {
                $folderUri = $this->reportDefaultFolder;
            }

            //Send out the event to the listener
            $folderEvent = new ReportFolderOpenEvent($folderUri);
            $this->eventDispatcher->dispatch('mesd.jasperreport.report_folder_open', $folderEvent);
            if ($folderEvent->isPropagationStopped()) {
                $folderUri = $folderEvent->getFolderUri();
            }

            //Open the folder uri in the jasper client and return it
            $contents = $this->jasperClient->getFolder($folderUri, $this->useFolderCache, $this->folderCacheDir, $this->folderCacheTimeout);

            //Get the parent uri
            $strippedUri = rtrim($folderUri, '/');
            $uriChunks = explode('/', $strippedUri);
            $parentUri = '';
            for($i = 0; $i < count($uriChunks) - 1; $i++) {
                if (!empty($uriChunks[$i])) {
                    $parentUri = $parentUri . '/' . $uriChunks[$i];
                }
            }

            return array($parentUri => $contents);
        }
    }

    //Get a report
    public function getReport($reportUri, $format = 'html') {
        if ($this->isConnected()) {
            $report = null;
            if ($report) {
                return $report;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     *  buildReport
     *
     *  takes in a reportUri and creates a ReportBuilder object and builds it
     *
     *  @return ReportBuilder
     */
    public function buildReport($reportUri, $format = 'html', $assetUrl = '') 
    {
        //Check connection
        if ($this->isConnected()) {
            //Get the report
            $report = new Report($reportUri, $format);

            //Create an instance of the report builder
            $reportBuilder = new ReportBuilder(
                  $this->jasperClient
                , $report
                , '&page=1'
                , $assetUrl
                , 'Fallback'
                );
        } else {
            return false;
        }
    }

    //Get a report view, like get report, but will handle query parameters and render the correct page
    //can be passed to the mesd_jasperreport_report_view twig function to automatically render the controls and report
    public function getReportView($reportUri, $format = 'html') {
        if ($this->isConnected()) {
            //Create an event with default params and pass it to the event listener to process
            $reportViewerEvent = new ReportViewerRequestEvent($format, 1);
            $this->eventDispatcher->dispatch('mesd.jasperreport.report_viewer_request', $reportViewerEvent);
            if ($reportViewerEvent->isPropagationStopped()) {
                if ($reportViewerEvent->isAsset()) {
                    return $this->getReportAsset($reportViewerEvent->getAssetUri(), $reportViewerEvent->getJSessionId());
                } else {
                    $params = '&page=' . $reportViewerEvent->getReportPage();
                    $format = $reportViewerEvent->getReportFormat();
                }
            } else {
                $params = '&page=1';
            }

            //Create new report object
            $report = new Report($reportUri, $format);

            //Create the builder
            $reportBuilder = new ReportBuilder(
                  $this->jasperClient
                , $report
                , $params
                , $this->router->getMatcher()->getContext()->getBaseUrl() . $this->router->getMatcher()->getContext()->getPathInfo() . '?asset=true'
                , 'Fallback'
                );

            return $reportBuilder;
        } else {
            return false;
        }
    }

    //Returns specified asset from a report with the specified jsessionid
    public function getReportAsset($assetUri, $jSessionId) {
        //Create a connection with the given jSessionId
        $assetClient = new Client($this->reportHost . ':' . $this->reportPort, $this->reportUser, $this->reportPass, $jSessionId);
        return $assetClient->getReportAsset($assetUri);
    }

    //Get input control
    public function getReportInputControl($resource, $getICFrom) {
        if ($this->isConnected()) {
            $inputControl = $this->jasperClient->getReportInputControl($resource, $getICFrom);
            if ($inputControl) {
                return $inputControl;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }


    /**
     * Returns a boolean indicating whether the client is connected to the jasper server or not
     *
     * @return boolean Whether the client is connected with the server or not
     */
    public function isConnected() {
        return $this->connected;
    }


    ////////////////////////
    // GETTERS AND SETTER //
    ////////////////////////


    /**
     * Gets the Reference to the jasper client that is initialized by the connect method
     *   with the parameters passed via dependency injection.
     *
     * @return JasperClient\Client\Client
     */
    public function getJasperClient()
    {
        return $this->jasperClient;
    }

    /**
     * Sets the Reference to the jasper client that is initialized by the connect method
     *   with the parameters passed via dependency injection.
     *
     * @param JasperClient\Client\Client $jasperClient the jasper client
     *
     * @return self
     */
    public function setJasperClient(JasperClient\Client\Client $jasperClient)
    {
        $this->jasperClient = $jasperClient;

        return $this;
    }

    /**
     * Gets the Default symfony route to send asset requests to.
     *
     * @return string
     */
    public function getDefaultAssetRoute()
    {
        return $this->defaultAssetRoute;
    }

    /**
     * Sets the Default symfony route to send asset requests to.
     *
     * @param string $defaultAssetRoute the default asset route
     *
     * @return self
     */
    public function setDefaultAssetRoute($defaultAssetRoute)
    {
        $this->defaultAssetRoute = $defaultAssetRoute;

        return $this;
    }

    /**
     * Gets the The Symfony Service Container.
     *
     * @return Symfony\Component\DependencyInjection\Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Sets the The Symfony Service Container.
     *
     * @param Symfony\Component\DependencyInjection\Container $container the container
     *
     * @return self
     */
    public function setContainer(Symfony\Component\DependencyInjection\Container $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Gets the The Host Name of the report server.
     *
     * @return string
     */
    public function getReportHost()
    {
        return $this->reportHost;
    }

    /**
     * Sets the The Host Name of the report server.
     *
     * @param string $reportHost the report host
     *
     * @return self
     */
    public function setReportHost($reportHost)
    {
        $this->reportHost = $reportHost;

        return $this;
    }

    /**
     * Gets the Jasper Servers port.
     *
     * @return string
     */
    public function getReportPort()
    {
        return $this->reportPort;
    }

    /**
     * Sets the Jasper Servers port.
     *
     * @param string $reportPort the report port
     *
     * @return self
     */
    public function setReportPort($reportPort)
    {
        $this->reportPort = $reportPort;

        return $this;
    }

    /**
     * Gets the Username for the jasper server account.
     *
     * @return string
     */
    public function getReportUsername()
    {
        return $this->reportUsername;
    }

    /**
     * Sets the Username for the jasper server account.
     *
     * @param string $reportUser the report user
     *
     * @return self
     */
    public function setReportUsername($reportUsername)
    {
        $this->reportUsername = $reportUsername;

        return $this;
    }

    /**
     * Gets the Password for the jasper server account.
     *
     * @return string
     */
    public function getReportPassword()
    {
        return $this->reportPassword;
    }

    /**
     * Sets the Password for the jasper server account.
     *
     * @param string $reportPass the report pass
     *
     * @return self
     */
    public function setReportPassword($reportPassword)
    {
        $this->reportPassword = $reportPassword;

        return $this;
    }

    /**
     * Gets the Whether to cache resource lists or not.
     *
     * @return boolean
     */
    public function getUseFolderCache()
    {
        return $this->useFolderCache;
    }

    /**
     * Sets the Whether to cache resource lists or not.
     *
     * @param boolean $useFolderCache the use folder cache
     *
     * @return self
     */
    public function setUseFolderCache($useFolderCache)
    {
        $this->useFolderCache = $useFolderCache;

        return $this;
    }

    /**
     * Gets the Directory of the resource list cache.
     *
     * @return string
     */
    public function getFolderCacheDir()
    {
        return $this->folderCacheDir;
    }

    /**
     * Sets the Directory of the resource list cache.
     *
     * @param string $folderCacheDir the folder cache dir
     *
     * @return self
     */
    public function setFolderCacheDir($folderCacheDir)
    {
        $this->folderCacheDir = $folderCacheDir;

        return $this;
    }

    /**
     * Gets the How long a resource list cache is considered fresh.
     *
     * @return int
     */
    public function getFolderCacheTimeout()
    {
        return $this->folderCacheTimeout;
    }

    /**
     * Sets the How long a resource list cache is considered fresh.
     *
     * @param int $folderCacheTimeout the folder cache timeout
     *
     * @return self
     */
    public function setFolderCacheTimeout($folderCacheTimeout)
    {
        $this->folderCacheTimeout = $folderCacheTimeout;

        return $this;
    }

    /**
     * Gets the Where to cache reports.
     *
     * @return string
     */
    public function getReportCacheDir()
    {
        return $this->reportCacheDir;
    }

    /**
     * Sets the Where to cache reports.
     *
     * @param string $reportCacheDir the report cache dir
     *
     * @return self
     */
    public function setReportCacheDir($reportCacheDir)
    {
        $this->reportCacheDir = $reportCacheDir;

        return $this;
    }

    /**
     * Gets the The service name of the application specific input control option handler.
     *
     * @return string
     */
    public function getOptionHandlerServiceName()
    {
        return $this->optionHandlerServiceName;
    }

    /**
     * Sets the The service name of the application specific input control option handler.
     *
     * @param string $optionHandlerServiceName the option handler service name
     *
     * @return self
     */
    public function setOptionHandlerServiceName($optionHandlerServiceName)
    {
        $this->optionHandlerServiceName = $optionHandlerServiceName;

        return $this;
    }

    /**
     * Gets the value of eventDispatcher.
     *
     * @return mixed
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * Sets the value of eventDispatcher.
     *
     * @param mixed $eventDispatcher the event dispatcher
     *
     * @return self
     */
    public function setEventDispatcher($eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Gets the value of router.
     *
     * @return mixed
     */
    public function getRouter()
    {
        return $this->router;
    }

    /**
     * Sets the value of router.
     *
     * @param mixed $router the router
     *
     * @return self
     */
    public function setRouter($router)
    {
        $this->router = $router;

        return $this;
    }

    /**
     * Gets the value of routeHelper.
     *
     * @return mixed
     */
    public function getRouteHelper()
    {
        return $this->routeHelper;
    }

    /**
     * Sets the value of routeHelper.
     *
     * @param mixed $routeHelper the route helper
     *
     * @return self
     */
    public function setRouteHelper($routeHelper)
    {
        $this->routeHelper = $routeHelper;

        return $this;
    }

    /**
     * Gets the Default Folder to go to when getting the resource list if no other folder is specified.
     *
     * @return string
     */
    public function getDefaultFolder()
    {
        return $this->defaultFolder;
    }

    /**
     * Sets the Default Folder to go to when getting the resource list if no other folder is specified.
     *
     * @param string $defaultFolder the default folder
     *
     * @return self
     */
    public function setDefaultFolder($defaultFolder)
    {
        $this->defaultFolder = $defaultFolder;

        return $this;
    }
}