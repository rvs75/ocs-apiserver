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
 * Created: 27.06.2017
 */
class Application_Model_Solr
{

    public $_pagination = null;

    /*
     * Pass in params like 'q', 'page', 'count'
     *
     */
    public function search($op = array())
    {
        $pagination_array = array();
        $output = null;

        $solr = $this->get_solr_connection();
        if ($solr->ping()) {

            $params = array(
                'defType'    => 'dismax',
                'wt'         => 'json',
                'fl'         => '*,score',
                'df'         => 'description',
                'qf'         => empty($op['qf']) ? 'title^3 description^2 username^1 cat_title' : $op['qf'],
                'bq'         => 'changed_at:[NOW-1YEAR TO NOW/DAY]',
                //'bf'         => 'if(lt(laplace_score,50),-10,10)',
                'bf'         => 'product(recip(ms(NOW/HOUR,changed_at),3.16e-11,0.2,0.2),1000)',
                'sort'       => 'score desc,changed_at desc',
                //'hl'          => 'on',
                //'hl.fl'       => 'title, description, username',
                //'facet'          => 'on',
                //'facet.field[0]' => 'project_category_id',
                //'facet.field[1]' => 'username',
                //'facet.mincount' => '1',
                'spellcheck' => 'true',
            );

            $params = $this->setStoreFilter($params);

            $offset = ((int)$op['page'] - 1) * (int)$op['count'];

            $query = trim($op['q']);

            $results = $solr->search($query, $offset, $op['count'], $params);

            $output['response'] = (array)$results->response;
            $output['responseHeader'] = (array)$results->responseHeader;
            $output['facet_counts'] = (array)$results->facet_counts;
            $output['highlighting'] = (array)$results->highlighting;
            $output['hits'] = (array)$results->response->docs;

            $pagination_array = array();
            if (isset($output['response']['numFound'])) {
                $pagination_array = array_combine(
                    range(0, $output['response']['numFound'] - 1),
                    range(1, $output['response']['numFound'])
                );
            }
        }
        $pagination = Zend_Paginator::factory($pagination_array);
        $pagination->setCurrentPageNumber($op['page']);
        $pagination->setItemCountPerPage($op['count']);
        $pagination->setPageRange(9);

        $this->_pagination = $pagination;

        return $output;
    }

    /**
     * @return Zend_Service_Solr
     * @throws Zend_Exception
     */
    private function get_solr_connection()
    {
        $config = Zend_Registry::get('config');
        $config_search = $config->settings->search;

        return new Zend_Service_Solr ($config_search->host, $config_search->port,
            $config_search->http_path); // Configure
    }

    /**
     * @param $input
     *
     * @return null|string|string[]
     */
    static public function escape($input)
    {
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';

        return preg_replace($pattern, '\\\$1', $input);
    }

    /**
     * @return null|Zend_Paginator
     * @throws Zend_Paginator_Exception
     */
    public function getPagination()
    {
        if (isset($this->_pagination)) {
            return $this->_pagination;
        }
        return Zend_Paginator::factory(array());
    }

    /**
     *
     * Get spell
     *
     * @param array $op
     *
     * @return array
     */
    public function spell($op = array())
    {
        $solr = $this->get_solr_connection();
        if ($solr->ping()) {

            $results = $solr->spell($op['q']);
            $results = json_decode($results, true);

            return $results['spellcheck'];
        }
    }

    /**
     * @param $object
     *
     * @return array
     */
    private function object_to_array($object)
    {
        if (is_array($object) || is_object($object)) {
            $result = array();
            foreach ($object as $key => $value) {
                $result[$key] = $this->object_to_array($value);
            }

            return $result;
        }

        return $object;
    }

    /**
     * @param $params
     *
     * @return mixed
     * @throws Zend_Exception
     */
    private function setStoreFilter($params)
    {
        $currentStoreConfig = Zend_Registry::get('store_config');
        if (substr($currentStoreConfig['order'], -1) <> 1) {
            return $params;
        }
        $params['fq'] = 'stores:('.$currentStoreConfig['store_id'].')';
        return $params;
    }

}