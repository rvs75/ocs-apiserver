<?php
/**
 *  ocs-apiserver
 *
 *  Copyright 2016 by pling GmbH.
 *
 *    This file is part of ocs-apiserver.
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU Affero General Public License as
 *    published by the Free Software Foundation, either version 3 of the
 *    License, or (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Created: 01.12.2017
 */

class Application_Plugin_InitGlobalStoreVars extends Zend_Controller_Plugin_Abstract
{

    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
        /** @var Zend_Controller_Request_Http $request */
        parent::preDispatch($request);

        $storeConfigName = $this->getStoreConfigName($request);
        Zend_Registry::set('store_config_name', $storeConfigName);
        Zend_Registry::set('store_template', $this->getStoreTemplate($storeConfigName));

        $storeHost = $this->getStoreHost($request);
        Zend_Registry::set('store_host', $storeHost);
        Zend_Registry::set('store_config', $this->getStoreConfig($storeHost));
        Zend_Registry::set('store_category_list', $this->getStoreCategories($storeHost));
    }

    /**
     * @param Zend_Controller_Request_Http $request
     *
     * @return string
     * @throws Zend_Exception
     */
    private function getStoreConfigName($request)
    {
        $storeIdName = Zend_Registry::get('config')->settings->client->default->name; //set to default

        $store_config_list = Zend_Registry::get('application_store_config_list');

        // search for store id param
        $requestStoreConfigName = null;
        if ($request->getParam('domain_store_id')) {
            $requestStoreConfigName = $request->getParam('domain_store_id') ? preg_replace('/[^-a-zA-Z0-9_\.]/', '',
                $request->getParam('domain_store_id')) : null;

            $result = $this->searchForConfig($store_config_list, 'name', $requestStoreConfigName);

            if (isset($result['config_id_name'])) {
                return $result['config_id_name'];
            } else {
                $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'undefined';
                Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - $requestStoreIdName = '
                    . $requestStoreConfigName . ' :: no config id name configured' . PHP_EOL
                    . 'HOST::        ' . $_SERVER['HTTP_HOST'] . PHP_EOL
                    . 'USER_AGENT::  ' . $userAgent . PHP_EOL
                    . 'REQUEST_URI:: ' . $_SERVER['REQUEST_URI'] . PHP_EOL
                    . 'ENVIRONMENT:: ' . APPLICATION_ENV . PHP_EOL
                    . 'store_config_list:' . print_r(array_column($store_config_list, 'name', 'host'), true))
                ;
            }
        }

        // search for host
        $httpHost = strtolower($request->getHttpHost());
        if (isset($store_config_list[$httpHost])) {
            return $store_config_list[$httpHost]['config_id_name'];
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - $httpHost = ' . $httpHost
                . ' :: no config id name configured')
            ;
        }

        // search for default
        $result = $this->searchForConfig($store_config_list, 'default', 1);

        if (isset($result['config_id_name'])) {
            $storeIdName = $result['config_id_name'];
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__
                . ') - no default store config name configured')
            ;
        }

        return $storeIdName;
    }


    /**
     * @param string $storeConfigName
     *
     * @return array|mixed
     * @throws Zend_Exception
     */
    private function getStoreTemplate($storeConfigName)
    {
        $storeTemplate = array();

        $fileNameConfig = APPLICATION_PATH . '/configs/client_' . $storeConfigName . '.ini.php';

        if (file_exists($fileNameConfig)) {
            $storeTemplate = require APPLICATION_PATH . '/configs/client_' . $storeConfigName . '.ini.php';
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . ' - ' . $storeConfigName
                . ' :: can not access config file for store context.')
            ;
            $this->raiseException(__METHOD__ . ' - ' . $storeConfigName
                . ' :: can not access config file for store context on line ' . __LINE__ );
        }

        return $storeTemplate;
    }

    private function raiseException($message)
    {
        $request = $this->getRequest();
        // Repoint the request to the default error handler
        $request->setModuleName('default');
        $request->setControllerName('error');
        $request->setActionName('error');
        //        $request->setDispatched(true);

        // Set up the error handler
        $error = new Zend_Controller_Plugin_ErrorHandler();
        $error->type = Zend_Controller_Plugin_ErrorHandler::EXCEPTION_OTHER;
        $error->request = clone($request);
        $error->exception = new Zend_Exception($message);
        $request->setParam('error_handler', $error);
        //        $this->setRequest($request);
    }

    /**
     * @param Zend_Controller_Request_Http $request
     *
     * @return mixed
     * @throws Zend_Exception
     */
    private function getStoreHost($request)
    {
        $storeHost = '';
        $storeConfigArray = Zend_Registry::get('application_store_config_list');

        // search for store id param
        $requestStoreConfigName = null;
        if ($request->getParam('domain_store_id')) {
            $requestStoreConfigName = $request->getParam('domain_store_id') ? preg_replace('/[^-a-zA-Z0-9_\.]/', '',
                $request->getParam('domain_store_id')) : null;

            $result = $this->searchForConfig($storeConfigArray, 'name', $requestStoreConfigName);

            if (isset($result['host'])) {
                $storeHost = $result['host'];

                return $storeHost;
            } else {
                $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'undefined';
                Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - $requestStoreConfigIdName = '
                    . $requestStoreConfigName . ' :: no config id name configured' . PHP_EOL
                    . 'HOST       :: ' . $_SERVER['HTTP_HOST'] . PHP_EOL
                    . 'USER_AGENT :: ' . $userAgent . PHP_EOL
                    . 'REQUEST_URI:: ' . $_SERVER['REQUEST_URI'] . PHP_EOL
                    . 'ENVIRONMENT:: ' . APPLICATION_ENV . PHP_EOL
                //. 'storeConfigArray:' . print_r(array_column($storeConfigArray, 'name', 'host'), true)
                );
            }
        }

        // search for host
        $httpHost = strtolower($request->getHttpHost());
        if (isset($storeConfigArray[$httpHost])) {
            return $storeConfigArray[$httpHost]['host'];
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - ' . $httpHost
                . ' :: no store config for host context configured')
            ;
        }

        // search for default
        $result = $this->searchForConfig($storeConfigArray, 'default', 1);

        if (isset($result['host'])) {
            $storeHost = $result['host'];
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - ' . $httpHost
                . ' :: no default store configured')
            ;
        }

        return $storeHost;
    }

    /**
     * @param string $storeHostName
     *
     * @return array
     * @throws Zend_Exception
     */
    private function getStoreConfig($storeHostName)
    {
        $storeConfig = array();

        $storeConfigArray = Zend_Registry::get('application_store_config_list');

        if (isset($storeConfigArray[$storeHostName])) {
            $storeConfig = $storeConfigArray[$storeHostName];
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - ' . $storeHostName
                . ' :: no domain config context configured')
            ;
        }

        return $storeConfig;
    }

    /**
     * @param string $storeHostName
     *
     * @return array
     * @throws Zend_Exception
     */
    private function getStoreCategories($storeHostName)
    {
        $storeCategoryArray = Zend_Registry::get('application_store_category_list');

        if (isset($storeCategoryArray[$storeHostName])) {
            $storeCategories = $storeCategoryArray[$storeHostName];
            if (is_string($storeCategories)) {
                $storeCategories = array($storeCategories);
            }
        } else {
            Zend_Registry::get('logger')->warn(__METHOD__ . '(' . __LINE__ . ') - ' . $storeHostName
                . ' :: no categories for domain context configured. Using main categories instead')
            ;
            $modelCategories = new Application_Model_DbTable_ProjectCategory();
            $root = $modelCategories->fetchRoot();
            $storeCategories = $modelCategories->fetchImmediateChildrenIds($root['project_category_id'],
                $modelCategories::ORDERED_TITLE);
        }

        return $storeCategories;
    }

    /**
     * needs PHP >= 5.5.0
     *
     * @param $haystack
     * @param $key
     * @param $value
     *
     * @return array
     */
    private function arraySearchConfig($haystack, $key, $value)
    {
        if (PHP_VERSION_ID <= 50500) {
            return $this->searchForConfig($haystack, $key, $value);
        }
        if (false === is_array($haystack)) {
            return array();
        }
        $key = array_search($value, array_column($haystack, $key, 'host'));
        if ($key) {
            return $haystack[$key];
        }

        return array();
    }

    /**
     * alternative version which replace arraySearchConfig for PHP < 5.5.0
     *
     * @param $haystack
     * @param $key
     * @param $value
     *
     * @return array
     */
    private function searchForConfig($haystack, $key, $value)
    {
        if (false === is_array($haystack)) {
            return array();
        }
        foreach ($haystack as $element) {
            if (isset($element[$key]) and (strtolower($element[$key]) == strtolower($value))) {
                return $element;
            }
        }

        return array();
    }

}