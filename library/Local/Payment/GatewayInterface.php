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
 **/

interface Local_Payment_GatewayInterface
{

    /**
     * @param array|Zend_config $config
     * @param Zend_Log_Writer_Abstract $logger
     */
    function __construct($config, $logger);

    /**
     * @return string
     */
    public function getCheckoutEndpoint();

    /**
     * @return Local_Payment_UserDataInterface
     */
    public function getUserDataStore();

    /**
     * @param float $amount
     * @param string $requestMsg
     * @return Local_Payment_ResponseInterface
     */
    public function requestPayment($amount, $requestMsg = null);

}